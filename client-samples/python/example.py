#!/usr/bin/env python3
"""
Example usage of the Python License Client
"""

import sys
import time
import threading
from pathlib import Path
from datetime import datetime

from license_client import LicenseClient, LicenseInfo, ActivationResult

# Embedded server public key (copy from your server)
SERVER_PUBLIC_KEY = """-----BEGIN PUBLIC KEY-----
MIICIjANBgkqhkiG9w0BAQEFAAOCAg8AMIICCgKCAgEA...
(Your actual server public key here)
-----END PUBLIC KEY-----"""

LICENSE_SERVER = "https://license.example.com/api/v1"
LICENSE_KEY = "XXXX-XXXX-XXXX-XXXX"  # Your license key

# Application start time for uptime calculation
START_TIME = datetime.now()


def check_license(client: LicenseClient) -> str:
    """
    Check and activate license

    Returns:
        Activation token if successful, None otherwise
    """
    print("Checking license...")

    activation_token = None

    # Try to load existing license file
    try:
        license_file = Path('license.lic')
        if license_file.exists():
            content = client.load_license_file('license.lic')

            # Verify signature
            if not client.verify_license_signature(content):
                print("❌ License file signature invalid!")
                return None

            print("✓ License file found and verified")

            # Load activation token
            token_file = Path('activation.token')
            if token_file.exists():
                activation_token = token_file.read_text().strip()

    except Exception as e:
        print(f"No existing license file found: {e}")

    # If no existing license, activate new one
    if not activation_token:
        print("Activating license...")
        result = client.activate(LICENSE_KEY)

        if not result.success:
            print(f"❌ Activation failed: {result.error}")
            return None

        print("✓ License activated successfully!")
        print(f"  Activation Token: {result.activation_token}")
        print(f"  Expires: {result.expires_at}")

        activation_token = result.activation_token

        # Save activation token
        Path('activation.token').write_text(activation_token)

    # Validate the license
    info = client.validate(LICENSE_KEY, activation_token)

    if not info.valid:
        print("❌ License validation failed!")
        print(f"  Status: {info.status}")
        return None

    print("✓ License is valid")
    print(f"  Product: {info.product_name} v{info.product_version}")
    print(f"  Expires: {info.expires_at}")
    print(f"  Days Remaining: {info.days_remaining}")

    # Check for expiry warnings
    if info.days_remaining <= 7:
        print(f"⚠️  WARNING: License expires in {info.days_remaining} days!")

    return activation_token


def heartbeat_loop(client: LicenseClient, license_key: str, activation_token: str):
    """
    Periodic heartbeat loop

    Args:
        client: License client
        license_key: License key
        activation_token: Activation token
    """
    APP_VERSION = "1.0.0"

    while True:
        # Wait 5 minutes between heartbeats
        time.sleep(300)

        try:
            uptime_seconds = int((datetime.now() - START_TIME).total_seconds())

            print("Sending heartbeat...")
            success = client.ping(license_key, activation_token, APP_VERSION, uptime_seconds)

            if success:
                print("✓ Heartbeat sent")
            else:
                print("❌ Heartbeat failed")

        except Exception as e:
            print(f"❌ Heartbeat error: {e}")


def run_application():
    """
    Simulate application running
    """
    print("Application running...")
    print("Press Ctrl+C to exit")

    counter = 0
    try:
        while True:
            time.sleep(1)

            # Example: Update status every 10 seconds
            if counter % 10 == 0:
                timestamp = datetime.now().strftime("%H:%M:%S")
                print(f"[{timestamp}] Application running... (Press Ctrl+C to exit)")

            counter += 1

    except KeyboardInterrupt:
        print("\nShutting down...")


def main():
    """Main entry point"""
    print("==================================")
    print("  MyApp Pro - Licensed Edition")
    print("==================================")
    print()

    try:
        # Initialize license client
        client = LicenseClient(LICENSE_SERVER, SERVER_PUBLIC_KEY)

        print(f"Hardware Fingerprint: {client.get_hardware_fingerprint()}")
        print()

        # Check and activate license
        activation_token = check_license(client)

        if not activation_token:
            print()
            print("❌ License check failed. Application will exit.")
            print("Please contact support@example.com for assistance.")
            sys.exit(1)

        print()
        print("✓ All license checks passed!")
        print("==================================")
        print()

        # Start heartbeat thread
        heartbeat_thread = threading.Thread(
            target=heartbeat_loop,
            args=(client, LICENSE_KEY, activation_token),
            daemon=True
        )
        heartbeat_thread.start()

        # Run application
        run_application()

        # Deactivate on exit
        print("Deactivating license...")
        deactivated = client.deactivate(LICENSE_KEY, activation_token)
        if deactivated:
            print("✓ License deactivated successfully")

    except KeyboardInterrupt:
        print("\nExiting...")
        sys.exit(0)

    except Exception as e:
        print(f"Error: {e}")
        import traceback
        traceback.print_exc()
        sys.exit(1)


if __name__ == '__main__':
    main()

"""
To run this example:

1. Install dependencies:
   pip install requests cryptography psutil

   Optional (for better hardware detection):
   pip install py-cpuinfo wmi  # wmi is Windows-only

2. Update SERVER_PUBLIC_KEY with your actual server public key

3. Update LICENSE_KEY with your license key

4. Run:
   python example.py

For production, consider:
- Obfuscating the Python bytecode (PyInstaller, Nuitka, etc.)
- Encrypting the embedded public key
- Using certificate pinning for HTTP requests
- Implementing anti-tampering measures
- Compiling to native executable (Nuitka, cx_Freeze, etc.)
"""
