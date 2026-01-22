// LicenseClient.h
// C++ Client Library for PHP License Server
// Requires: libcurl, OpenSSL, nlohmann/json

#ifndef LICENSE_CLIENT_H
#define LICENSE_CLIENT_H

#include <string>
#include <map>
#include <vector>
#include <memory>
#include <curl/curl.h>
#include <openssl/rsa.h>
#include <openssl/pem.h>
#include <openssl/sha.h>
#include <nlohmann/json.hpp>

namespace LicenseSDK {

using json = nlohmann::json;

// Hardware information structure
struct HardwareInfo {
    std::string cpu_id;
    std::string mac_address;
    std::string disk_serial;
    std::string motherboard_id;
    std::string bios_serial;
    std::string hostname;
    std::string os_info;
    int ram_mb;
};

// License information structure
struct LicenseInfo {
    std::string license_key;
    std::string activation_token;
    std::string status;
    std::string expires_at;
    int days_remaining;
    std::string product_name;
    std::string product_version;
    bool valid;
};

// Activation result
struct ActivationResult {
    bool success;
    std::string message;
    std::string activation_token;
    std::string license_file;
    std::string expires_at;
    std::string error;
};

class LicenseClient {
public:
    /**
     * Constructor
     * @param server_url Base URL of the license server (e.g., "https://license.example.com/api/v1")
     * @param server_public_key PEM-encoded public key of the server for signature verification
     */
    LicenseClient(const std::string& server_url, const std::string& server_public_key);

    /**
     * Destructor
     */
    ~LicenseClient();

    /**
     * Activate a license on this machine
     * @param license_key The license key to activate
     * @return ActivationResult with success status and activation token
     */
    ActivationResult activate(const std::string& license_key);

    /**
     * Validate an activated license
     * @param license_key The license key
     * @param activation_token The activation token received during activation
     * @return LicenseInfo with validation results
     */
    LicenseInfo validate(const std::string& license_key, const std::string& activation_token);

    /**
     * Deactivate the license on this machine
     * @param license_key The license key
     * @param activation_token The activation token
     * @return true if successful
     */
    bool deactivate(const std::string& license_key, const std::string& activation_token);

    /**
     * Send heartbeat/ping to server
     * @param license_key The license key
     * @param activation_token The activation token
     * @param app_version Current application version
     * @param uptime_seconds Application uptime in seconds
     * @return true if successful
     */
    bool ping(const std::string& license_key, const std::string& activation_token,
              const std::string& app_version, int uptime_seconds);

    /**
     * Get hardware fingerprint for this machine
     * @return Hardware fingerprint hash
     */
    std::string getHardwareFingerprint();

    /**
     * Get detailed hardware information
     * @return HardwareInfo structure
     */
    HardwareInfo getHardwareInfo();

    /**
     * Load license from file
     * @param filename Path to license file
     * @return License content
     */
    std::string loadLicenseFile(const std::string& filename);

    /**
     * Save license to file
     * @param filename Path to save license
     * @param content License content
     * @return true if successful
     */
    bool saveLicenseFile(const std::string& filename, const std::string& content);

    /**
     * Verify license file signature
     * @param license_content License file content
     * @return true if signature is valid
     */
    bool verifyLicenseSignature(const std::string& license_content);

private:
    std::string server_url_;
    std::string server_public_key_;
    RSA* server_rsa_key_;
    CURL* curl_;

    // Generate machine-specific RSA keypair
    void generateMachineKeypair();
    std::string machine_public_key_;
    std::string machine_private_key_;

    // HTTP request helpers
    std::string makeRequest(const std::string& endpoint, const std::string& method,
                           const json& data);
    static size_t writeCallback(void* contents, size_t size, size_t nmemb, void* userp);

    // Hardware detection
    std::string getCPUId();
    std::string getMACAddress();
    std::string getDiskSerial();
    std::string getMotherboardId();
    std::string getBIOSSerial();
    std::string getHostname();
    std::string getOSInfo();
    int getRAMSize();

    // Crypto helpers
    std::string sha256(const std::string& data);
    std::string base64Encode(const unsigned char* buffer, size_t length);
    std::vector<unsigned char> base64Decode(const std::string& encoded);
};

} // namespace LicenseSDK

#endif // LICENSE_CLIENT_H
