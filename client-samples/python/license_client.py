#!/usr/bin/env python3
"""
Python Client Library for PHP License Server
Requires: requests, cryptography

Install dependencies:
    pip install requests cryptography psutil
"""

import hashlib
import json
import platform
import socket
import uuid
from typing import Dict, Optional, Tuple
from pathlib import Path

import requests
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import rsa, padding
from cryptography.hazmat.backends import default_backend

try:
    import psutil
except ImportError:
    psutil = None


class HardwareInfo:
    """Hardware information for machine binding"""

    def __init__(self):
        self.cpu_id = self._get_cpu_id()
        self.mac_address = self._get_mac_address()
        self.disk_serial = self._get_disk_serial()
        self.motherboard_id = self._get_motherboard_id()
        self.bios_serial = self._get_bios_serial()
        self.hostname = socket.gethostname()
        self.os_info = f"{platform.system()} {platform.release()}"
        self.ram_mb = self._get_ram_size()

    def _get_cpu_id(self) -> str:
        """Get CPU identifier"""
        try:
            import cpuinfo
            info = cpuinfo.get_cpu_info()
            return info.get('brand_raw', 'Unknown')
        except:
            return platform.processor() or 'Unknown'

    def _get_mac_address(self) -> str:
        """Get MAC address"""
        mac = uuid.getnode()
        mac_str = ':'.join(('%012X' % mac)[i:i+2] for i in range(0, 12, 2))
        return mac_str

    def _get_disk_serial(self) -> str:
        """Get disk serial number"""
        if platform.system() == 'Windows':
            try:
                import wmi
                c = wmi.WMI()
                for disk in c.Win32_DiskDrive():
                    if disk.SerialNumber:
                        return disk.SerialNumber.strip()
            except:
                pass
        elif platform.system() == 'Linux':
            try:
                with open('/sys/class/block/sda/device/serial', 'r') as f:
                    return f.read().strip()
            except:
                pass

        return 'Unknown'

    def _get_motherboard_id(self) -> str:
        """Get motherboard identifier"""
        if platform.system() == 'Windows':
            try:
                import wmi
                c = wmi.WMI()
                for board in c.Win32_BaseBoard():
                    if board.SerialNumber:
                        return board.SerialNumber.strip()
            except:
                pass
        elif platform.system() == 'Linux':
            try:
                with open('/sys/devices/virtual/dmi/id/board_serial', 'r') as f:
                    return f.read().strip()
            except:
                pass

        return 'Unknown'

    def _get_bios_serial(self) -> str:
        """Get BIOS serial"""
        if platform.system() == 'Windows':
            try:
                import wmi
                c = wmi.WMI()
                for bios in c.Win32_BIOS():
                    if bios.SerialNumber:
                        return bios.SerialNumber.strip()
            except:
                pass
        elif platform.system() == 'Linux':
            try:
                with open('/sys/devices/virtual/dmi/id/bios_version', 'r') as f:
                    return f.read().strip()
            except:
                pass

        return 'Unknown'

    def _get_ram_size(self) -> int:
        """Get RAM size in MB"""
        if psutil:
            return psutil.virtual_memory().total // (1024 * 1024)
        return 0

    def to_dict(self) -> Dict:
        """Convert to dictionary"""
        return {
            'hostname': self.hostname,
            'os': self.os_info,
            'cpu': self.cpu_id,
            'ram': f"{self.ram_mb}MB"
        }


class LicenseInfo:
    """License validation information"""

    def __init__(self, data: Dict):
        self.valid = data.get('valid', False)
        self.status = data.get('status', '')
        self.expires_at = data.get('expires_at', '')
        self.days_remaining = data.get('days_remaining', 0)
        self.product_name = data.get('product', {}).get('name', '')
        self.product_version = data.get('product', {}).get('version', '')

    def __str__(self):
        return (f"Valid: {self.valid}, Status: {self.status}, "
                f"Product: {self.product_name} v{self.product_version}, "
                f"Expires: {self.expires_at}, Days Remaining: {self.days_remaining}")


class ActivationResult:
    """Activation result"""

    def __init__(self, data: Dict):
        self.success = data.get('success', False)
        self.message = data.get('message', '')
        self.activation_token = data.get('activation_token', '')
        self.license_file = data.get('license_file', '')
        self.expires_at = data.get('expires_at', '')
        self.error = data.get('error', '')


class LicenseClient:
    """Main license client for communicating with the license server"""

    def __init__(self, server_url: str, server_public_key: str):
        """
        Initialize the license client

        Args:
            server_url: Base URL of the license server API
            server_public_key: PEM-encoded server public key for signature verification
        """
        self.server_url = server_url.rstrip('/')
        self.server_public_key = server_public_key
        self.session = requests.Session()
        self.session.headers.update({'Content-Type': 'application/json'})

        # Load server public key
        self._load_server_public_key()

        # Generate machine keypair
        self._generate_machine_keypair()

    def _load_server_public_key(self):
        """Load server public key for signature verification"""
        self._server_rsa = serialization.load_pem_public_key(
            self.server_public_key.encode('utf-8'),
            backend=default_backend()
        )

    def _generate_machine_keypair(self):
        """Generate RSA keypair for this machine"""
        # Generate 2048-bit RSA keypair
        private_key = rsa.generate_private_key(
            public_exponent=65537,
            key_size=2048,
            backend=default_backend()
        )

        # Export public key
        public_key_pem = private_key.public_key().public_bytes(
            encoding=serialization.Encoding.PEM,
            format=serialization.PublicFormat.SubjectPublicKeyInfo
        )
        self.machine_public_key = public_key_pem.decode('utf-8')

        # Export private key (keep in memory only)
        private_key_pem = private_key.private_bytes(
            encoding=serialization.Encoding.PEM,
            format=serialization.PrivateFormat.PKCS8,
            encryption_algorithm=serialization.NoEncryption()
        )
        self.machine_private_key = private_key_pem.decode('utf-8')

    def activate(self, license_key: str) -> ActivationResult:
        """
        Activate a license on this machine

        Args:
            license_key: License key to activate

        Returns:
            ActivationResult with success status and activation token
        """
        try:
            fingerprint = self.get_hardware_fingerprint()
            hw_info = HardwareInfo()

            data = {
                'license_key': license_key,
                'machine_fingerprint': fingerprint,
                'machine_public_key': self.machine_public_key,
                'machine_info': hw_info.to_dict()
            }

            response = self._post('/activate', data)
            result = ActivationResult(response)

            # Save license file if successful
            if result.success and result.license_file:
                self.save_license_file('license.lic', result.license_file)

            return result

        except Exception as e:
            return ActivationResult({
                'success': False,
                'error': f'Activation failed: {str(e)}'
            })

    def validate(self, license_key: str, activation_token: str) -> LicenseInfo:
        """
        Validate an activated license

        Args:
            license_key: License key
            activation_token: Activation token from activation

        Returns:
            LicenseInfo with validation results
        """
        try:
            fingerprint = self.get_hardware_fingerprint()

            data = {
                'license_key': license_key,
                'machine_fingerprint': fingerprint,
                'activation_token': activation_token
            }

            response = self._post('/validate', data)
            return LicenseInfo(response)

        except Exception as e:
            return LicenseInfo({
                'valid': False,
                'status': f'Error: {str(e)}'
            })

    def deactivate(self, license_key: str, activation_token: str) -> bool:
        """
        Deactivate the license on this machine

        Args:
            license_key: License key
            activation_token: Activation token

        Returns:
            True if successful
        """
        try:
            fingerprint = self.get_hardware_fingerprint()

            data = {
                'license_key': license_key,
                'machine_fingerprint': fingerprint,
                'activation_token': activation_token
            }

            response = self._post('/deactivate', data)
            return response.get('success', False)

        except:
            return False

    def ping(self, license_key: str, activation_token: str,
             app_version: str, uptime_seconds: int) -> bool:
        """
        Send heartbeat/ping to server

        Args:
            license_key: License key
            activation_token: Activation token
            app_version: Application version
            uptime_seconds: Application uptime in seconds

        Returns:
            True if successful
        """
        try:
            fingerprint = self.get_hardware_fingerprint()

            data = {
                'license_key': license_key,
                'machine_fingerprint': fingerprint,
                'activation_token': activation_token,
                'app_version': app_version,
                'uptime_seconds': uptime_seconds
            }

            response = self._post('/ping', data)
            return response.get('success', False)

        except:
            return False

    def get_hardware_fingerprint(self) -> str:
        """
        Get hardware fingerprint for this machine

        Returns:
            SHA256 hash of hardware components
        """
        hw = HardwareInfo()

        data = (f"CPU:{hw.cpu_id}|MAC:{hw.mac_address}|DISK:{hw.disk_serial}|"
                f"MOBO:{hw.motherboard_id}|BIOS:{hw.bios_serial}")

        return hashlib.sha256(data.encode('utf-8')).hexdigest()

    def get_hardware_info(self) -> HardwareInfo:
        """
        Get detailed hardware information

        Returns:
            HardwareInfo structure
        """
        return HardwareInfo()

    def load_license_file(self, filename: str) -> str:
        """
        Load license file from disk

        Args:
            filename: Path to license file

        Returns:
            License file content
        """
        return Path(filename).read_text()

    def save_license_file(self, filename: str, content: str):
        """
        Save license file to disk

        Args:
            filename: Path to save license
            content: License content
        """
        Path(filename).write_text(content)

    def verify_license_signature(self, license_content: str) -> bool:
        """
        Verify license file signature using server public key

        Args:
            license_content: License file content

        Returns:
            True if signature is valid
        """
        try:
            # Extract signature from license file
            sig_index = license_content.find('---SIGNATURE---')
            if sig_index < 0:
                return False

            data = license_content[:sig_index]
            signature_b64 = license_content[sig_index + 15:].strip()

            # Decode signature
            import base64
            signature = base64.b64decode(signature_b64)

            # Verify signature
            self._server_rsa.verify(
                signature,
                data.encode('utf-8'),
                padding.PKCS1v15(),
                hashes.SHA256()
            )
            return True

        except:
            return False

    def _post(self, endpoint: str, data: Dict) -> Dict:
        """
        Make POST request to server

        Args:
            endpoint: API endpoint
            data: Request data

        Returns:
            Response data as dictionary
        """
        url = self.server_url + endpoint
        response = self.session.post(url, json=data)
        response.raise_for_status()
        return response.json()


if __name__ == '__main__':
    # Quick test
    print("Testing hardware detection...")
    hw = HardwareInfo()
    print(f"CPU: {hw.cpu_id}")
    print(f"MAC: {hw.mac_address}")
    print(f"Disk: {hw.disk_serial}")
    print(f"Motherboard: {hw.motherboard_id}")
    print(f"BIOS: {hw.bios_serial}")
    print(f"Hostname: {hw.hostname}")
    print(f"OS: {hw.os_info}")
    print(f"RAM: {hw.ram_mb} MB")

    # Test client initialization
    server_key = """-----BEGIN PUBLIC KEY-----
MIICIjANBgkqhkiG9w0BAQEFAAOCAg8AMIICCgKCAgEA...
-----END PUBLIC KEY-----"""

    client = LicenseClient("https://license.example.com/api/v1", server_key)
    print(f"\nHardware Fingerprint: {client.get_hardware_fingerprint()}")
    print("\nClient initialized successfully!")
