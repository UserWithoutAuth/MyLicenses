# Client Library Samples

Complete client library implementations for integrating with the PHP License Server in C++, C#, and Python.

## Overview

These client libraries allow your applications to:
- ✅ Activate licenses on user machines
- ✅ Validate licenses before application startup
- ✅ Generate hardware fingerprints for machine binding
- ✅ Send periodic heartbeats to the license server
- ✅ Verify license file signatures
- ✅ Handle offline activation scenarios
- ✅ Deactivate licenses when needed

## Available Languages

### 1. **C++ Library** (`/cpp`)
- Modern C++17
- Cross-platform (Windows, Linux, macOS)
- Dependencies: libcurl, OpenSSL, nlohmann/json
- Header-only option available
- High performance
- Hardware detection included

### 2. **C# Library** (`/csharp`)
- .NET Standard 2.0+
- .NET Core 3.1+ / .NET 5+
- Native dependencies: System.Management
- Full async/await support
- WMI for hardware detection (Windows)
- Cross-platform support

### 3. **Python Library** (`/python`)
- Python 3.7+
- Dependencies: requests, cryptography, psutil
- Simple and clean API
- Cross-platform
- Easy to integrate

## Quick Start

### C++ Example

```cpp
#include "LicenseClient.h"

using namespace LicenseSDK;

const char* SERVER_PUBLIC_KEY = R"(-----BEGIN PUBLIC KEY-----
... your server public key ...
-----END PUBLIC KEY-----)";

int main() {
    try {
        LicenseClient client("https://license.example.com/api/v1", SERVER_PUBLIC_KEY);

        // Activate license
        ActivationResult result = client.activate("XXXX-XXXX-XXXX-XXXX");
        if (!result.success) {
            std::cerr << "Activation failed: " << result.error << std::endl;
            return 1;
        }

        // Validate license
        LicenseInfo info = client.validate("XXXX-XXXX-XXXX-XXXX", result.activation_token);
        if (!info.valid) {
            std::cerr << "License invalid!" << std::endl;
            return 1;
        }

        std::cout << "License valid! Expires: " << info.expires_at << std::endl;

        // Your application code here

    } catch (const std::exception& e) {
        std::cerr << "Error: " << e.what() << std::endl;
        return 1;
    }
    return 0;
}
```

**Build:**
```bash
g++ -std=c++17 example.cpp LicenseClient.cpp -o myapp \
    -lcurl -lssl -lcrypto -lpthread \
    -I/path/to/nlohmann/json/include
```

### C# Example

```csharp
using LicenseSDK;

const string SERVER_PUBLIC_KEY = @"-----BEGIN PUBLIC KEY-----
... your server public key ...
-----END PUBLIC KEY-----";

using (var client = new LicenseClient("https://license.example.com/api/v1", SERVER_PUBLIC_KEY))
{
    // Activate license
    var result = await client.ActivateAsync("XXXX-XXXX-XXXX-XXXX");
    if (!result.Success)
    {
        Console.WriteLine($"Activation failed: {result.Error}");
        return;
    }

    // Validate license
    var info = await client.ValidateAsync("XXXX-XXXX-XXXX-XXXX", result.ActivationToken);
    if (!info.Valid)
    {
        Console.WriteLine("License invalid!");
        return;
    }

    Console.WriteLine($"License valid! Expires: {info.ExpiresAt}");

    // Your application code here
}
```

**Build:**
```bash
dotnet new console -n MyApp
cd MyApp
dotnet add package System.Management
dotnet add package System.Text.Json
# Copy LicenseClient.cs to project
dotnet build -c Release
```

### Python Example

```python
from license_client import LicenseClient

SERVER_PUBLIC_KEY = """-----BEGIN PUBLIC KEY-----
... your server public key ...
-----END PUBLIC KEY-----"""

# Initialize client
client = LicenseClient("https://license.example.com/api/v1", SERVER_PUBLIC_KEY)

# Activate license
result = client.activate("XXXX-XXXX-XXXX-XXXX")
if not result.success:
    print(f"Activation failed: {result.error}")
    exit(1)

# Validate license
info = client.validate("XXXX-XXXX-XXXX-XXXX", result.activation_token)
if not info.valid:
    print("License invalid!")
    exit(1)

print(f"License valid! Expires: {info.expires_at}")

# Your application code here
```

**Run:**
```bash
pip install requests cryptography psutil
python example.py
```

## Features Comparison

| Feature | C++ | C# | Python |
|---------|-----|----|----|
| License Activation | ✅ | ✅ | ✅ |
| License Validation | ✅ | ✅ | ✅ |
| Offline Activation | ✅ | ✅ | ✅ |
| Hardware Fingerprinting | ✅ | ✅ | ✅ |
| Signature Verification | ✅ | ✅ | ✅ |
| Heartbeat/Ping | ✅ | ✅ | ✅ |
| Async Operations | ❌ | ✅ | ✅ |
| Cross-Platform | ✅ | ✅ | ✅ |
| Native Performance | ✅ | ✅ | ❌ |
| Easy Integration | ⚠️ | ✅ | ✅ |

## Installation

### C++ Requirements

**Linux:**
```bash
# Ubuntu/Debian
sudo apt-get install libcurl4-openssl-dev libssl-dev nlohmann-json3-dev

# Fedora/RHEL
sudo dnf install libcurl-devel openssl-devel json-devel
```

**Windows:**
```powershell
# Using vcpkg
vcpkg install curl openssl nlohmann-json
```

**macOS:**
```bash
brew install curl openssl nlohmann-json
```

### C# Requirements

```bash
dotnet add package System.Management
dotnet add package System.Text.Json
```

### Python Requirements

```bash
pip install requests cryptography psutil

# Optional for better hardware detection
pip install py-cpuinfo
pip install wmi  # Windows only
```

## Hardware Detection

All libraries detect the following hardware components:

- **CPU ID** - Processor identifier
- **MAC Address** - Primary network adapter
- **Disk Serial** - Primary disk serial number
- **Motherboard ID** - Motherboard serial
- **BIOS Serial** - BIOS version/serial
- **Hostname** - Machine hostname
- **OS Info** - Operating system and version
- **RAM Size** - Total RAM in MB

### Hardware Fingerprint Generation

The hardware fingerprint is a SHA256 hash of:
```
CPU:{cpu_id}|MAC:{mac_address}|DISK:{disk_serial}|MOBO:{motherboard_id}|BIOS:{bios_serial}
```

This creates a unique identifier for each machine while being:
- **Deterministic** - Same machine = same fingerprint
- **Secure** - Can't be easily forged
- **Stable** - Survives minor hardware changes
- **Privacy-friendly** - No personally identifiable information

## API Reference

### LicenseClient

#### Constructor

```cpp
// C++
LicenseClient(const std::string& server_url, const std::string& server_public_key);
```

```csharp
// C#
LicenseClient(string serverUrl, string serverPublicKey);
```

```python
# Python
LicenseClient(server_url: str, server_public_key: str)
```

**Parameters:**
- `server_url` - Base URL of the license server API
- `server_public_key` - PEM-encoded server public key

#### activate()

Activate a license on the current machine.

```cpp
// C++
ActivationResult activate(const std::string& license_key);
```

```csharp
// C#
Task<ActivationResult> ActivateAsync(string licenseKey);
```

```python
# Python
activate(license_key: str) -> ActivationResult
```

**Returns:**
- `ActivationResult` with `activation_token` and `license_file`

#### validate()

Validate an activated license.

```cpp
// C++
LicenseInfo validate(const std::string& license_key, const std::string& activation_token);
```

```csharp
// C#
Task<LicenseInfo> ValidateAsync(string licenseKey, string activationToken);
```

```python
# Python
validate(license_key: str, activation_token: str) -> LicenseInfo
```

**Returns:**
- `LicenseInfo` with validation status and license details

#### deactivate()

Deactivate the license on the current machine.

```cpp
// C++
bool deactivate(const std::string& license_key, const std::string& activation_token);
```

```csharp
// C#
Task<bool> DeactivateAsync(string licenseKey, string activationToken);
```

```python
# Python
deactivate(license_key: str, activation_token: str) -> bool
```

**Returns:**
- `true`/`True` if successful

#### ping()

Send heartbeat to server.

```cpp
// C++
bool ping(const std::string& license_key, const std::string& activation_token,
         const std::string& app_version, int uptime_seconds);
```

```csharp
// C#
Task<bool> PingAsync(string licenseKey, string activationToken,
                     string appVersion, int uptimeSeconds);
```

```python
# Python
ping(license_key: str, activation_token: str, app_version: str, uptime_seconds: int) -> bool
```

**Returns:**
- `true`/`True` if successful

#### getHardwareFingerprint()

Get hardware fingerprint for the current machine.

```cpp
// C++
std::string getHardwareFingerprint();
```

```csharp
// C#
string GetHardwareFingerprint();
```

```python
# Python
get_hardware_fingerprint() -> str
```

**Returns:**
- SHA256 hash of hardware components

#### verifyLicenseSignature()

Verify license file signature.

```cpp
// C++
bool verifyLicenseSignature(const std::string& license_content);
```

```csharp
// C#
bool VerifyLicenseSignature(string licenseContent);
```

```python
# Python
verify_license_signature(license_content: str) -> bool
```

**Returns:**
- `true`/`True` if signature is valid

## Integration Guide

### Step 1: Get Server Public Key

From your license server, copy the server's public key:

```bash
cat storage/keys/server_public.key
```

Embed this in your application code.

### Step 2: Initialize Client

```cpp
// C++
LicenseClient client(SERVER_URL, SERVER_PUBLIC_KEY);
```

```csharp
// C#
var client = new LicenseClient(SERVER_URL, SERVER_PUBLIC_KEY);
```

```python
# Python
client = LicenseClient(SERVER_URL, SERVER_PUBLIC_KEY)
```

### Step 3: Check License on Startup

```cpp
// Pseudo-code (all languages similar)
if (license_file_exists) {
    load license_file
    verify signature
    validate license
} else {
    activate license
    save license_file
    save activation_token
}

if (!license_valid) {
    exit application
}
```

### Step 4: Send Periodic Heartbeats

```cpp
// In a background thread (every 5 minutes)
while (running) {
    sleep(5 minutes);
    client.ping(license_key, activation_token, app_version, uptime);
}
```

### Step 5: Deactivate on Exit

```cpp
// On application exit
client.deactivate(license_key, activation_token);
```

## Security Best Practices

### 1. **Protect the Server Public Key**

❌ **Don't:**
```cpp
const char* key = "-----BEGIN PUBLIC KEY-----\nMIIC...";  // Plaintext
```

✅ **Do:**
```cpp
// Obfuscate the key in production
const char* key = decrypt_embedded_key();  // Encrypted/obfuscated
```

### 2. **Validate Before Every Critical Operation**

```cpp
// Check license before sensitive operations
if (!validate_license()) {
    return ERROR_LICENSE_INVALID;
}

// Continue with operation
perform_sensitive_operation();
```

### 3. **Store Activation Token Securely**

❌ **Don't:**
```cpp
file.write(activation_token);  // Plain file
```

✅ **Do:**
```cpp
encrypted = encrypt_token(activation_token);
secure_storage.write(encrypted);  // OS keychain/credential manager
```

### 4. **Implement Anti-Tampering**

- Use code obfuscation
- Implement checksum validation
- Detect debuggers
- Verify binary integrity
- Use packing/encryption

### 5. **Use Certificate Pinning**

```cpp
// Pin the server's SSL certificate
curl_easy_setopt(curl, CURLOPT_SSL_VERIFYPEER, 1L);
curl_easy_setopt(curl, CURLOPT_PINNEDPUBLICKEY, "sha256//...");
```

### 6. **Handle Errors Gracefully**

```cpp
try {
    client.validate(key, token);
} catch (NetworkException& e) {
    // Allow grace period for network issues
    if (last_validation < 24_hours_ago) {
        allow_usage();
    }
}
```

## Offline Activation

For air-gapped environments:

### Client Side:
```cpp
// 1. Generate activation request
auto request = client.createOfflineActivationRequest(license_key);

// 2. Save to file
save_file("activation_request.txt", request);

// 3. User transfers file to online machine

// 4. Upload to server and get response

// 5. Load activation response
auto response = load_file("activation_response.txt");

// 6. Complete offline activation
client.completeOfflineActivation(response);
```

### Server API:
```bash
# Online machine - upload request
curl -X POST https://license.example.com/api/v1/offline/request \
  -d @activation_request.txt > activation_response.txt

# Transfer activation_response.txt back to offline machine
```

## Troubleshooting

### Issue: "Failed to get hardware fingerprint"

**Solution:** Run with elevated permissions (sudo/admin) or adjust permissions for hardware access.

### Issue: "Certificate verification failed"

**Solution:** Ensure OpenSSL/system certificates are up to date:
```bash
# Linux
sudo update-ca-certificates

# Windows
# Update via Windows Update
```

### Issue: "License validation fails after hardware change"

**Solution:** The server tracks hardware similarity. Minor changes are tolerated, but major changes require reactivation:
```cpp
// Deactivate old activation
client.deactivate(old_token);

// Activate on new hardware
client.activate(license_key);
```

### Issue: "Build errors in C++"

**Solution:** Ensure all dependencies are installed and paths are correct:
```bash
# Check installed libraries
pkg-config --libs libcurl openssl

# Verify includes
ls /usr/include/nlohmann/
```

## Testing

### Test Activation

```bash
# C++
./test_client activate YOUR_LICENSE_KEY

# C#
dotnet run -- activate YOUR_LICENSE_KEY

# Python
python test_client.py activate YOUR_LICENSE_KEY
```

### Test Validation

```bash
# C++
./test_client validate YOUR_LICENSE_KEY YOUR_ACTIVATION_TOKEN

# C#
dotnet run -- validate YOUR_LICENSE_KEY YOUR_ACTIVATION_TOKEN

# Python
python test_client.py validate YOUR_LICENSE_KEY YOUR_ACTIVATION_TOKEN
```

### Test Hardware Detection

```bash
# C++
./test_client hardware

# C#
dotnet run -- hardware

# Python
python test_client.py hardware
```

## Production Deployment

### Checklist:

- [ ] Obfuscate/encrypt embedded server public key
- [ ] Enable code obfuscation (ProGuard, Dotfuscator, PyArmor, etc.)
- [ ] Implement anti-debugging measures
- [ ] Use HTTPS with certificate pinning
- [ ] Store activation tokens securely (OS credential manager)
- [ ] Implement grace periods for network failures
- [ ] Log license checks for audit trail
- [ ] Test on target platforms
- [ ] Implement automatic updates
- [ ] Add telemetry/crash reporting
- [ ] Code signing (Windows Authenticode, macOS notarization)
- [ ] Test in isolated/sandboxed environments

## License

These client libraries are provided as samples for integration with the PHP License Server. Modify and use as needed for your applications.

## Support

For issues or questions:
- Check the main README.md
- Review API documentation
- Contact support@example.com

---

**Last Updated**: 2026-01-22
**Version**: 1.0.0
