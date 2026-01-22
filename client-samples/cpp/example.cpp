// example.cpp
// Example usage of the C++ License Client

#include "LicenseClient.h"
#include <iostream>
#include <thread>
#include <chrono>

// Embedded server public key (copy from your server's public key)
const char* SERVER_PUBLIC_KEY = R"(-----BEGIN PUBLIC KEY-----
MIICIjANBgkqhkiG9w0BAQEFAAOCAg8AMIICCgKCAgEA...
(Your actual server public key here)
-----END PUBLIC KEY-----)";

const std::string LICENSE_SERVER = "https://license.example.com/api/v1";
const std::string LICENSE_KEY = "XXXX-XXXX-XXXX-XXXX"; // Your license key

using namespace LicenseSDK;

// Function to check and activate license
bool checkLicense(LicenseClient& client, std::string& activation_token) {
    std::cout << "Checking license..." << std::endl;

    // Try to load existing license file
    try {
        std::string license_file = client.loadLicenseFile("license.lic");

        // Verify signature
        if (!client.verifyLicenseSignature(license_file)) {
            std::cerr << "❌ License file signature invalid!" << std::endl;
            return false;
        }

        std::cout << "✓ License file found and verified" << std::endl;

        // Parse activation token from file (you'd need to implement this)
        // For now, assume it's stored separately
        std::ifstream token_file("activation.token");
        if (token_file.is_open()) {
            std::getline(token_file, activation_token);
            token_file.close();
        }

    } catch (const std::exception& e) {
        std::cout << "No existing license file found" << std::endl;

        // Activate new license
        std::cout << "Activating license..." << std::endl;
        ActivationResult result = client.activate(LICENSE_KEY);

        if (!result.success) {
            std::cerr << "❌ Activation failed: " << result.error << std::endl;
            return false;
        }

        std::cout << "✓ License activated successfully!" << std::endl;
        std::cout << "  Activation Token: " << result.activation_token << std::endl;
        std::cout << "  Expires: " << result.expires_at << std::endl;

        activation_token = result.activation_token;

        // Save activation token
        std::ofstream token_file("activation.token");
        token_file << activation_token;
        token_file.close();
    }

    // Validate the license
    LicenseInfo info = client.validate(LICENSE_KEY, activation_token);

    if (!info.valid) {
        std::cerr << "❌ License validation failed!" << std::endl;
        std::cerr << "  Status: " << info.status << std::endl;
        return false;
    }

    std::cout << "✓ License is valid" << std::endl;
    std::cout << "  Product: " << info.product_name << " v" << info.product_version << std::endl;
    std::cout << "  Expires: " << info.expires_at << std::endl;
    std::cout << "  Days Remaining: " << info.days_remaining << std::endl;

    // Check for expiry warnings
    if (info.days_remaining <= 7) {
        std::cout << "⚠️  WARNING: License expires in " << info.days_remaining << " days!" << std::endl;
    }

    return true;
}

// Function to send periodic heartbeats
void heartbeatThread(LicenseClient& client, const std::string& license_key,
                     const std::string& activation_token) {
    const std::string APP_VERSION = "1.0.0";
    auto start_time = std::chrono::steady_clock::now();

    while (true) {
        // Sleep for 5 minutes
        std::this_thread::sleep_for(std::chrono::minutes(5));

        // Calculate uptime
        auto now = std::chrono::steady_clock::now();
        int uptime_seconds = std::chrono::duration_cast<std::chrono::seconds>(
            now - start_time).count();

        // Send ping
        std::cout << "Sending heartbeat..." << std::endl;
        bool success = client.ping(license_key, activation_token, APP_VERSION, uptime_seconds);

        if (success) {
            std::cout << "✓ Heartbeat sent" << std::endl;
        } else {
            std::cerr << "❌ Heartbeat failed" << std::endl;
        }
    }
}

int main() {
    std::cout << "==================================" << std::endl;
    std::cout << "  MyApp Pro - Licensed Edition" << std::endl;
    std::cout << "==================================" << std::endl;
    std::cout << std::endl;

    try {
        // Initialize license client
        LicenseClient client(LICENSE_SERVER, SERVER_PUBLIC_KEY);

        std::cout << "Hardware Fingerprint: " << client.getHardwareFingerprint() << std::endl;
        std::cout << std::endl;

        // Check and activate license
        std::string activation_token;
        if (!checkLicense(client, activation_token)) {
            std::cerr << std::endl;
            std::cerr << "❌ License check failed. Application will exit." << std::endl;
            std::cerr << "Please contact support@example.com for assistance." << std::endl;
            return 1;
        }

        std::cout << std::endl;
        std::cout << "✓ All license checks passed!" << std::endl;
        std::cout << "==================================" << std::endl;
        std::cout << std::endl;

        // Start heartbeat thread
        std::thread heartbeat(heartbeatThread, std::ref(client), LICENSE_KEY, activation_token);
        heartbeat.detach();

        // Your application code here
        std::cout << "Application running..." << std::endl;
        std::cout << "Press Ctrl+C to exit" << std::endl;

        // Keep application running
        while (true) {
            std::this_thread::sleep_for(std::chrono::seconds(1));

            // Your application logic here
            // ...
        }

    } catch (const std::exception& e) {
        std::cerr << "Error: " << e.what() << std::endl;
        return 1;
    }

    return 0;
}

// Build instructions:
// g++ -std=c++17 example.cpp LicenseClient.cpp -o myapp \
//     -lcurl -lssl -lcrypto -lpthread \
//     -I/path/to/nlohmann/json/include

// Windows build:
// cl /EHsc example.cpp LicenseClient.cpp /I"C:\path\to\json\include" ^
//    /link libcurl.lib ssleay32.lib libeay32.lib ws2_32.lib
