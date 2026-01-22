// LicenseClient.cpp
// Implementation of C++ License Client

#include "LicenseClient.h"
#include <iostream>
#include <fstream>
#include <sstream>
#include <cstring>
#include <algorithm>

#ifdef _WIN32
#include <windows.h>
#include <iphlpapi.h>
#include <intrin.h>
#pragma comment(lib, "iphlpapi.lib")
#else
#include <unistd.h>
#include <sys/utsname.h>
#include <sys/sysinfo.h>
#include <net/if.h>
#include <sys/ioctl.h>
#include <netinet/in.h>
#endif

namespace LicenseSDK {

// Constructor
LicenseClient::LicenseClient(const std::string& server_url, const std::string& server_public_key)
    : server_url_(server_url), server_public_key_(server_public_key), server_rsa_key_(nullptr) {

    // Initialize cURL
    curl_global_init(CURL_GLOBAL_DEFAULT);
    curl_ = curl_easy_init();

    // Load server public key
    BIO* bio = BIO_new_mem_buf(server_public_key_.c_str(), -1);
    server_rsa_key_ = PEM_read_bio_RSA_PUBKEY(bio, nullptr, nullptr, nullptr);
    BIO_free(bio);

    if (!server_rsa_key_) {
        throw std::runtime_error("Failed to load server public key");
    }

    // Generate machine keypair
    generateMachineKeypair();
}

// Destructor
LicenseClient::~LicenseClient() {
    if (server_rsa_key_) {
        RSA_free(server_rsa_key_);
    }
    if (curl_) {
        curl_easy_cleanup(curl_);
    }
    curl_global_cleanup();
}

// Activate license
ActivationResult LicenseClient::activate(const std::string& license_key) {
    ActivationResult result;

    try {
        std::string fingerprint = getHardwareFingerprint();
        HardwareInfo hw_info = getHardwareInfo();

        json request_data = {
            {"license_key", license_key},
            {"machine_fingerprint", fingerprint},
            {"machine_public_key", machine_public_key_},
            {"machine_info", {
                {"hostname", hw_info.hostname},
                {"os", hw_info.os_info},
                {"cpu", hw_info.cpu_id},
                {"ram", std::to_string(hw_info.ram_mb) + "MB"}
            }}
        };

        std::string response = makeRequest("/activate", "POST", request_data);
        json response_json = json::parse(response);

        result.success = response_json["success"];
        if (result.success) {
            result.message = response_json["message"];
            result.activation_token = response_json["activation_token"];
            result.license_file = response_json["license_file"];
            result.expires_at = response_json["expires_at"];

            // Save license file
            saveLicenseFile("license.lic", result.license_file);
        } else {
            result.error = response_json["error"];
        }

    } catch (const std::exception& e) {
        result.success = false;
        result.error = std::string("Activation failed: ") + e.what();
    }

    return result;
}

// Validate license
LicenseInfo LicenseClient::validate(const std::string& license_key, const std::string& activation_token) {
    LicenseInfo info;
    info.valid = false;

    try {
        std::string fingerprint = getHardwareFingerprint();

        json request_data = {
            {"license_key", license_key},
            {"machine_fingerprint", fingerprint},
            {"activation_token", activation_token}
        };

        std::string response = makeRequest("/validate", "POST", request_data);
        json response_json = json::parse(response);

        info.valid = response_json["valid"];
        info.status = response_json["status"];
        info.license_key = license_key;
        info.activation_token = activation_token;

        if (info.valid) {
            info.expires_at = response_json["expires_at"];
            info.days_remaining = response_json["days_remaining"];
            info.product_name = response_json["product"]["name"];
            info.product_version = response_json["product"]["version"];
        }

    } catch (const std::exception& e) {
        std::cerr << "Validation error: " << e.what() << std::endl;
    }

    return info;
}

// Deactivate license
bool LicenseClient::deactivate(const std::string& license_key, const std::string& activation_token) {
    try {
        std::string fingerprint = getHardwareFingerprint();

        json request_data = {
            {"license_key", license_key},
            {"machine_fingerprint", fingerprint},
            {"activation_token", activation_token}
        };

        std::string response = makeRequest("/deactivate", "POST", request_data);
        json response_json = json::parse(response);

        return response_json["success"];

    } catch (const std::exception& e) {
        std::cerr << "Deactivation error: " << e.what() << std::endl;
        return false;
    }
}

// Send ping
bool LicenseClient::ping(const std::string& license_key, const std::string& activation_token,
                        const std::string& app_version, int uptime_seconds) {
    try {
        std::string fingerprint = getHardwareFingerprint();

        json request_data = {
            {"license_key", license_key},
            {"machine_fingerprint", fingerprint},
            {"activation_token", activation_token},
            {"app_version", app_version},
            {"uptime_seconds", uptime_seconds}
        };

        std::string response = makeRequest("/ping", "POST", request_data);
        json response_json = json::parse(response);

        return response_json["success"];

    } catch (const std::exception& e) {
        std::cerr << "Ping error: " << e.what() << std::endl;
        return false;
    }
}

// Get hardware fingerprint
std::string LicenseClient::getHardwareFingerprint() {
    HardwareInfo hw = getHardwareInfo();

    std::ostringstream oss;
    oss << "CPU:" << hw.cpu_id << "|"
        << "MAC:" << hw.mac_address << "|"
        << "DISK:" << hw.disk_serial << "|"
        << "MOBO:" << hw.motherboard_id << "|"
        << "BIOS:" << hw.bios_serial;

    return sha256(oss.str());
}

// Get hardware info
HardwareInfo LicenseClient::getHardwareInfo() {
    HardwareInfo info;
    info.cpu_id = getCPUId();
    info.mac_address = getMACAddress();
    info.disk_serial = getDiskSerial();
    info.motherboard_id = getMotherboardId();
    info.bios_serial = getBIOSSerial();
    info.hostname = getHostname();
    info.os_info = getOSInfo();
    info.ram_mb = getRAMSize();
    return info;
}

// Load license file
std::string LicenseClient::loadLicenseFile(const std::string& filename) {
    std::ifstream file(filename);
    if (!file.is_open()) {
        throw std::runtime_error("Cannot open license file");
    }

    std::stringstream buffer;
    buffer << file.rdbuf();
    return buffer.str();
}

// Save license file
bool LicenseClient::saveLicenseFile(const std::string& filename, const std::string& content) {
    std::ofstream file(filename, std::ios::binary);
    if (!file.is_open()) {
        return false;
    }

    file << content;
    file.close();
    return true;
}

// Verify license signature
bool LicenseClient::verifyLicenseSignature(const std::string& license_content) {
    // Extract signature from license file
    // Format: LICENSE_DATA\n---SIGNATURE---\nBASE64_SIGNATURE

    size_t sig_pos = license_content.find("---SIGNATURE---");
    if (sig_pos == std::string::npos) {
        return false;
    }

    std::string data = license_content.substr(0, sig_pos);
    std::string sig_b64 = license_content.substr(sig_pos + 15);
    sig_b64.erase(std::remove(sig_b64.begin(), sig_b64.end(), '\n'), sig_b64.end());

    // Decode signature
    std::vector<unsigned char> signature = base64Decode(sig_b64);

    // Hash the data
    unsigned char hash[SHA256_DIGEST_LENGTH];
    SHA256(reinterpret_cast<const unsigned char*>(data.c_str()), data.length(), hash);

    // Verify signature
    int result = RSA_verify(NID_sha256, hash, SHA256_DIGEST_LENGTH,
                           signature.data(), signature.size(), server_rsa_key_);

    return result == 1;
}

// Generate machine keypair
void LicenseClient::generateMachineKeypair() {
    // Generate RSA keypair (2048-bit for performance)
    BIGNUM* bne = BN_new();
    BN_set_word(bne, RSA_F4);

    RSA* rsa = RSA_new();
    RSA_generate_key_ex(rsa, 2048, bne, nullptr);

    // Extract public key
    BIO* bio_pub = BIO_new(BIO_s_mem());
    PEM_write_bio_RSA_PUBKEY(bio_pub, rsa);

    char* pub_key_data;
    long pub_key_len = BIO_get_mem_data(bio_pub, &pub_key_data);
    machine_public_key_ = std::string(pub_key_data, pub_key_len);
    BIO_free(bio_pub);

    // Extract private key
    BIO* bio_priv = BIO_new(BIO_s_mem());
    PEM_write_bio_RSAPrivateKey(bio_priv, rsa, nullptr, nullptr, 0, nullptr, nullptr);

    char* priv_key_data;
    long priv_key_len = BIO_get_mem_data(bio_priv, &priv_key_data);
    machine_private_key_ = std::string(priv_key_data, priv_key_len);
    BIO_free(bio_priv);

    RSA_free(rsa);
    BN_free(bne);
}

// Make HTTP request
std::string LicenseClient::makeRequest(const std::string& endpoint, const std::string& method,
                                      const json& data) {
    std::string url = server_url_ + endpoint;
    std::string response_data;

    curl_easy_setopt(curl_, CURLOPT_URL, url.c_str());
    curl_easy_setopt(curl_, CURLOPT_WRITEFUNCTION, writeCallback);
    curl_easy_setopt(curl_, CURLOPT_WRITEDATA, &response_data);

    // Set method
    if (method == "POST") {
        curl_easy_setopt(curl_, CURLOPT_POST, 1L);
        std::string json_data = data.dump();
        curl_easy_setopt(curl_, CURLOPT_POSTFIELDS, json_data.c_str());
    }

    // Set headers
    struct curl_slist* headers = nullptr;
    headers = curl_slist_append(headers, "Content-Type: application/json");
    curl_easy_setopt(curl_, CURLOPT_HTTPHEADER, headers);

    // Perform request
    CURLcode res = curl_easy_perform(curl_);
    curl_slist_free_all(headers);

    if (res != CURLE_OK) {
        throw std::runtime_error(curl_easy_strerror(res));
    }

    return response_data;
}

// cURL write callback
size_t LicenseClient::writeCallback(void* contents, size_t size, size_t nmemb, void* userp) {
    ((std::string*)userp)->append((char*)contents, size * nmemb);
    return size * nmemb;
}

// Hardware detection implementations
std::string LicenseClient::getCPUId() {
#ifdef _WIN32
    int cpuInfo[4] = {-1};
    __cpuid(cpuInfo, 0);

    char cpu_string[13];
    memcpy(cpu_string, &cpuInfo[1], 4);
    memcpy(cpu_string + 4, &cpuInfo[3], 4);
    memcpy(cpu_string + 8, &cpuInfo[2], 4);
    cpu_string[12] = '\0';

    return std::string(cpu_string);
#else
    // Read from /proc/cpuinfo
    std::ifstream cpuinfo("/proc/cpuinfo");
    std::string line;
    while (std::getline(cpuinfo, line)) {
        if (line.find("model name") != std::string::npos) {
            size_t pos = line.find(":");
            return line.substr(pos + 2);
        }
    }
    return "Unknown";
#endif
}

std::string LicenseClient::getMACAddress() {
#ifdef _WIN32
    IP_ADAPTER_INFO adapterInfo[16];
    DWORD bufLen = sizeof(adapterInfo);

    if (GetAdaptersInfo(adapterInfo, &bufLen) == NO_ERROR) {
        char mac[18];
        snprintf(mac, sizeof(mac), "%02X:%02X:%02X:%02X:%02X:%02X",
                adapterInfo[0].Address[0], adapterInfo[0].Address[1],
                adapterInfo[0].Address[2], adapterInfo[0].Address[3],
                adapterInfo[0].Address[4], adapterInfo[0].Address[5]);
        return std::string(mac);
    }
#else
    struct ifreq ifr;
    int sock = socket(AF_INET, SOCK_DGRAM, 0);

    strcpy(ifr.ifr_name, "eth0");
    if (ioctl(sock, SIOCGIFHWADDR, &ifr) == 0) {
        unsigned char* mac = (unsigned char*)ifr.ifr_hwaddr.sa_data;
        char mac_str[18];
        snprintf(mac_str, sizeof(mac_str), "%02X:%02X:%02X:%02X:%02X:%02X",
                mac[0], mac[1], mac[2], mac[3], mac[4], mac[5]);
        close(sock);
        return std::string(mac_str);
    }
    close(sock);
#endif
    return "00:00:00:00:00:00";
}

std::string LicenseClient::getDiskSerial() {
#ifdef _WIN32
    DWORD serialNum = 0;
    GetVolumeInformation("C:\\", nullptr, 0, &serialNum, nullptr, nullptr, nullptr, 0);
    char serial[16];
    snprintf(serial, sizeof(serial), "%08X", serialNum);
    return std::string(serial);
#else
    // Try to read disk serial from various sources
    std::ifstream serial_file("/sys/class/block/sda/device/serial");
    if (serial_file.is_open()) {
        std::string serial;
        std::getline(serial_file, serial);
        return serial;
    }
    return "Unknown";
#endif
}

std::string LicenseClient::getMotherboardId() {
#ifdef _WIN32
    // Would use WMI to get motherboard serial
    return "Unknown";
#else
    std::ifstream dmi("/sys/devices/virtual/dmi/id/board_serial");
    if (dmi.is_open()) {
        std::string serial;
        std::getline(dmi, serial);
        return serial;
    }
    return "Unknown";
#endif
}

std::string LicenseClient::getBIOSSerial() {
#ifdef _WIN32
    // Would use WMI to get BIOS serial
    return "Unknown";
#else
    std::ifstream bios("/sys/devices/virtual/dmi/id/bios_version");
    if (bios.is_open()) {
        std::string version;
        std::getline(bios, version);
        return version;
    }
    return "Unknown";
#endif
}

std::string LicenseClient::getHostname() {
    char hostname[256];
    gethostname(hostname, sizeof(hostname));
    return std::string(hostname);
}

std::string LicenseClient::getOSInfo() {
#ifdef _WIN32
    return "Windows";
#else
    struct utsname buffer;
    if (uname(&buffer) == 0) {
        return std::string(buffer.sysname) + " " + buffer.release;
    }
    return "Unknown";
#endif
}

int LicenseClient::getRAMSize() {
#ifdef _WIN32
    MEMORYSTATUSEX status;
    status.dwLength = sizeof(status);
    GlobalMemoryStatusEx(&status);
    return status.ullTotalPhys / (1024 * 1024);
#else
    struct sysinfo info;
    if (sysinfo(&info) == 0) {
        return info.totalram / (1024 * 1024);
    }
    return 0;
#endif
}

// SHA256 hash
std::string LicenseClient::sha256(const std::string& data) {
    unsigned char hash[SHA256_DIGEST_LENGTH];
    SHA256(reinterpret_cast<const unsigned char*>(data.c_str()), data.length(), hash);

    std::ostringstream oss;
    for (int i = 0; i < SHA256_DIGEST_LENGTH; i++) {
        oss << std::hex << std::setw(2) << std::setfill('0') << (int)hash[i];
    }
    return oss.str();
}

// Base64 encode
std::string LicenseClient::base64Encode(const unsigned char* buffer, size_t length) {
    static const char base64_chars[] =
        "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";

    std::string ret;
    int i = 0;
    unsigned char char_array_3[3];
    unsigned char char_array_4[4];

    while (length--) {
        char_array_3[i++] = *(buffer++);
        if (i == 3) {
            char_array_4[0] = (char_array_3[0] & 0xfc) >> 2;
            char_array_4[1] = ((char_array_3[0] & 0x03) << 4) + ((char_array_3[1] & 0xf0) >> 4);
            char_array_4[2] = ((char_array_3[1] & 0x0f) << 2) + ((char_array_3[2] & 0xc0) >> 6);
            char_array_4[3] = char_array_3[2] & 0x3f;

            for (i = 0; i < 4; i++)
                ret += base64_chars[char_array_4[i]];
            i = 0;
        }
    }

    if (i) {
        for (int j = i; j < 3; j++)
            char_array_3[j] = '\0';

        char_array_4[0] = (char_array_3[0] & 0xfc) >> 2;
        char_array_4[1] = ((char_array_3[0] & 0x03) << 4) + ((char_array_3[1] & 0xf0) >> 4);
        char_array_4[2] = ((char_array_3[1] & 0x0f) << 2) + ((char_array_3[2] & 0xc0) >> 6);

        for (int j = 0; j < i + 1; j++)
            ret += base64_chars[char_array_4[j]];

        while (i++ < 3)
            ret += '=';
    }

    return ret;
}

// Base64 decode
std::vector<unsigned char> LicenseClient::base64Decode(const std::string& encoded) {
    static const std::string base64_chars =
        "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";

    std::vector<unsigned char> ret;
    int i = 0;
    unsigned char char_array_4[4], char_array_3[3];

    for (char c : encoded) {
        if (c == '=') break;
        if (!isalnum(c) && c != '+' && c != '/') continue;

        char_array_4[i++] = c;
        if (i == 4) {
            for (i = 0; i < 4; i++)
                char_array_4[i] = base64_chars.find(char_array_4[i]);

            char_array_3[0] = (char_array_4[0] << 2) + ((char_array_4[1] & 0x30) >> 4);
            char_array_3[1] = ((char_array_4[1] & 0xf) << 4) + ((char_array_4[2] & 0x3c) >> 2);
            char_array_3[2] = ((char_array_4[2] & 0x3) << 6) + char_array_4[3];

            for (i = 0; i < 3; i++)
                ret.push_back(char_array_3[i]);
            i = 0;
        }
    }

    if (i) {
        for (int j = i; j < 4; j++)
            char_array_4[j] = 0;

        for (int j = 0; j < 4; j++)
            char_array_4[j] = base64_chars.find(char_array_4[j]);

        char_array_3[0] = (char_array_4[0] << 2) + ((char_array_4[1] & 0x30) >> 4);
        char_array_3[1] = ((char_array_4[1] & 0xf) << 4) + ((char_array_4[2] & 0x3c) >> 2);

        for (int j = 0; j < i - 1; j++)
            ret.push_back(char_array_3[j]);
    }

    return ret;
}

} // namespace LicenseSDK
