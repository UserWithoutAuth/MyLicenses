// LicenseClient.cs
// C# Client Library for PHP License Server
// .NET Standard 2.0+ / .NET Core 3.1+ / .NET 5+

using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Management;
using System.Net;
using System.Net.Http;
using System.Net.NetworkInformation;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;
using System.Threading.Tasks;

namespace LicenseSDK
{
    /// <summary>
    /// Hardware information for machine binding
    /// </summary>
    public class HardwareInfo
    {
        public string CpuId { get; set; }
        public string MacAddress { get; set; }
        public string DiskSerial { get; set; }
        public string MotherboardId { get; set; }
        public string BiosSerial { get; set; }
        public string Hostname { get; set; }
        public string OsInfo { get; set; }
        public int RamMB { get; set; }
    }

    /// <summary>
    /// License validation information
    /// </summary>
    public class LicenseInfo
    {
        [JsonPropertyName("valid")]
        public bool Valid { get; set; }

        [JsonPropertyName("status")]
        public string Status { get; set; }

        [JsonPropertyName("expires_at")]
        public string ExpiresAt { get; set; }

        [JsonPropertyName("days_remaining")]
        public int DaysRemaining { get; set; }

        [JsonPropertyName("product")]
        public ProductInfo Product { get; set; }

        public string LicenseKey { get; set; }
        public string ActivationToken { get; set; }
    }

    /// <summary>
    /// Product information
    /// </summary>
    public class ProductInfo
    {
        [JsonPropertyName("name")]
        public string Name { get; set; }

        [JsonPropertyName("version")]
        public string Version { get; set; }
    }

    /// <summary>
    /// Activation result
    /// </summary>
    public class ActivationResult
    {
        [JsonPropertyName("success")]
        public bool Success { get; set; }

        [JsonPropertyName("message")]
        public string Message { get; set; }

        [JsonPropertyName("activation_token")]
        public string ActivationToken { get; set; }

        [JsonPropertyName("license_file")]
        public string LicenseFile { get; set; }

        [JsonPropertyName("expires_at")]
        public string ExpiresAt { get; set; }

        [JsonPropertyName("error")]
        public string Error { get; set; }
    }

    /// <summary>
    /// Main license client for communicating with the license server
    /// </summary>
    public class LicenseClient : IDisposable
    {
        private readonly string _serverUrl;
        private readonly string _serverPublicKey;
        private readonly HttpClient _httpClient;
        private RSA _serverRsa;
        private RSA _machineRsa;
        private string _machinePublicKey;
        private string _machinePrivateKey;

        /// <summary>
        /// Initialize the license client
        /// </summary>
        /// <param name="serverUrl">Base URL of the license server API</param>
        /// <param name="serverPublicKey">PEM-encoded server public key for signature verification</param>
        public LicenseClient(string serverUrl, string serverPublicKey)
        {
            _serverUrl = serverUrl.TrimEnd('/');
            _serverPublicKey = serverPublicKey;
            _httpClient = new HttpClient();

            // Load server public key
            LoadServerPublicKey();

            // Generate machine keypair
            GenerateMachineKeypair();
        }

        /// <summary>
        /// Activate a license on this machine
        /// </summary>
        /// <param name="licenseKey">License key to activate</param>
        /// <returns>Activation result with token and license file</returns>
        public async Task<ActivationResult> ActivateAsync(string licenseKey)
        {
            try
            {
                var fingerprint = GetHardwareFingerprint();
                var hwInfo = GetHardwareInfo();

                var requestData = new
                {
                    license_key = licenseKey,
                    machine_fingerprint = fingerprint,
                    machine_public_key = _machinePublicKey,
                    machine_info = new
                    {
                        hostname = hwInfo.Hostname,
                        os = hwInfo.OsInfo,
                        cpu = hwInfo.CpuId,
                        ram = $"{hwInfo.RamMB}MB"
                    }
                };

                var result = await PostAsync<ActivationResult>("/activate", requestData);

                // Save license file if successful
                if (result.Success && !string.IsNullOrEmpty(result.LicenseFile))
                {
                    SaveLicenseFile("license.lic", result.LicenseFile);
                }

                return result;
            }
            catch (Exception ex)
            {
                return new ActivationResult
                {
                    Success = false,
                    Error = $"Activation failed: {ex.Message}"
                };
            }
        }

        /// <summary>
        /// Validate an activated license
        /// </summary>
        /// <param name="licenseKey">License key</param>
        /// <param name="activationToken">Activation token from activation</param>
        /// <returns>License information and validation status</returns>
        public async Task<LicenseInfo> ValidateAsync(string licenseKey, string activationToken)
        {
            try
            {
                var fingerprint = GetHardwareFingerprint();

                var requestData = new
                {
                    license_key = licenseKey,
                    machine_fingerprint = fingerprint,
                    activation_token = activationToken
                };

                var result = await PostAsync<LicenseInfo>("/validate", requestData);
                result.LicenseKey = licenseKey;
                result.ActivationToken = activationToken;

                return result;
            }
            catch (Exception ex)
            {
                return new LicenseInfo
                {
                    Valid = false,
                    Status = $"Error: {ex.Message}"
                };
            }
        }

        /// <summary>
        /// Deactivate the license on this machine
        /// </summary>
        /// <param name="licenseKey">License key</param>
        /// <param name="activationToken">Activation token</param>
        /// <returns>True if successful</returns>
        public async Task<bool> DeactivateAsync(string licenseKey, string activationToken)
        {
            try
            {
                var fingerprint = GetHardwareFingerprint();

                var requestData = new
                {
                    license_key = licenseKey,
                    machine_fingerprint = fingerprint,
                    activation_token = activationToken
                };

                var result = await PostAsync<Dictionary<string, object>>("/deactivate", requestData);
                return result.ContainsKey("success") && (bool)result["success"];
            }
            catch
            {
                return false;
            }
        }

        /// <summary>
        /// Send heartbeat/ping to server
        /// </summary>
        /// <param name="licenseKey">License key</param>
        /// <param name="activationToken">Activation token</param>
        /// <param name="appVersion">Application version</param>
        /// <param name="uptimeSeconds">Application uptime in seconds</param>
        /// <returns>True if successful</returns>
        public async Task<bool> PingAsync(string licenseKey, string activationToken,
                                          string appVersion, int uptimeSeconds)
        {
            try
            {
                var fingerprint = GetHardwareFingerprint();

                var requestData = new
                {
                    license_key = licenseKey,
                    machine_fingerprint = fingerprint,
                    activation_token = activationToken,
                    app_version = appVersion,
                    uptime_seconds = uptimeSeconds
                };

                var result = await PostAsync<Dictionary<string, object>>("/ping", requestData);
                return result.ContainsKey("success") && (bool)result["success"];
            }
            catch
            {
                return false;
            }
        }

        /// <summary>
        /// Get hardware fingerprint for this machine
        /// </summary>
        /// <returns>SHA256 hash of hardware components</returns>
        public string GetHardwareFingerprint()
        {
            var hw = GetHardwareInfo();

            var data = $"CPU:{hw.CpuId}|MAC:{hw.MacAddress}|DISK:{hw.DiskSerial}|" +
                      $"MOBO:{hw.MotherboardId}|BIOS:{hw.BiosSerial}";

            using (var sha256 = SHA256.Create())
            {
                var hash = sha256.ComputeHash(Encoding.UTF8.GetBytes(data));
                return BitConverter.ToString(hash).Replace("-", "").ToLower();
            }
        }

        /// <summary>
        /// Get detailed hardware information
        /// </summary>
        /// <returns>Hardware information structure</returns>
        public HardwareInfo GetHardwareInfo()
        {
            return new HardwareInfo
            {
                CpuId = GetCpuId(),
                MacAddress = GetMacAddress(),
                DiskSerial = GetDiskSerial(),
                MotherboardId = GetMotherboardId(),
                BiosSerial = GetBiosSerial(),
                Hostname = Environment.MachineName,
                OsInfo = GetOsInfo(),
                RamMB = GetRamSize()
            };
        }

        /// <summary>
        /// Load license file from disk
        /// </summary>
        /// <param name="filename">Path to license file</param>
        /// <returns>License file content</returns>
        public string LoadLicenseFile(string filename)
        {
            return File.ReadAllText(filename);
        }

        /// <summary>
        /// Save license file to disk
        /// </summary>
        /// <param name="filename">Path to save license</param>
        /// <param name="content">License content</param>
        public void SaveLicenseFile(string filename, string content)
        {
            File.WriteAllText(filename, content);
        }

        /// <summary>
        /// Verify license file signature using server public key
        /// </summary>
        /// <param name="licenseContent">License file content</param>
        /// <returns>True if signature is valid</returns>
        public bool VerifyLicenseSignature(string licenseContent)
        {
            try
            {
                // Extract signature from license file
                var sigIndex = licenseContent.IndexOf("---SIGNATURE---");
                if (sigIndex < 0) return false;

                var data = licenseContent.Substring(0, sigIndex);
                var signatureB64 = licenseContent.Substring(sigIndex + 15).Trim();

                // Decode signature
                var signature = Convert.FromBase64String(signatureB64);

                // Hash the data
                byte[] hash;
                using (var sha256 = SHA256.Create())
                {
                    hash = sha256.ComputeHash(Encoding.UTF8.GetBytes(data));
                }

                // Verify signature
                return _serverRsa.VerifyHash(hash, signature, HashAlgorithmName.SHA256,
                                            RSASignaturePadding.Pkcs1);
            }
            catch
            {
                return false;
            }
        }

        #region Private Methods

        private void LoadServerPublicKey()
        {
            _serverRsa = RSA.Create();

            // Remove PEM headers/footers
            var keyData = _serverPublicKey
                .Replace("-----BEGIN PUBLIC KEY-----", "")
                .Replace("-----END PUBLIC KEY-----", "")
                .Replace("\n", "")
                .Replace("\r", "");

            var keyBytes = Convert.FromBase64String(keyData);
            _serverRsa.ImportSubjectPublicKeyInfo(keyBytes, out _);
        }

        private void GenerateMachineKeypair()
        {
            _machineRsa = RSA.Create(2048);

            // Export public key
            var publicKeyBytes = _machineRsa.ExportSubjectPublicKeyInfo();
            _machinePublicKey = "-----BEGIN PUBLIC KEY-----\n" +
                               Convert.ToBase64String(publicKeyBytes, Base64FormattingOptions.InsertLineBreaks) +
                               "\n-----END PUBLIC KEY-----";

            // Export private key (keep in memory only)
            var privateKeyBytes = _machineRsa.ExportRSAPrivateKey();
            _machinePrivateKey = "-----BEGIN RSA PRIVATE KEY-----\n" +
                                Convert.ToBase64String(privateKeyBytes, Base64FormattingOptions.InsertLineBreaks) +
                                "\n-----END RSA PRIVATE KEY-----";
        }

        private async Task<T> PostAsync<T>(string endpoint, object data)
        {
            var url = _serverUrl + endpoint;
            var json = JsonSerializer.Serialize(data);
            var content = new StringContent(json, Encoding.UTF8, "application/json");

            var response = await _httpClient.PostAsync(url, content);
            response.EnsureSuccessStatusCode();

            var responseJson = await response.Content.ReadAsStringAsync();
            return JsonSerializer.Deserialize<T>(responseJson,
                new JsonSerializerOptions { PropertyNameCaseInsensitive = true });
        }

        #endregion

        #region Hardware Detection

        private string GetCpuId()
        {
            try
            {
                using (var searcher = new ManagementObjectSearcher("SELECT ProcessorId FROM Win32_Processor"))
                {
                    foreach (var obj in searcher.Get())
                    {
                        return obj["ProcessorId"]?.ToString() ?? "Unknown";
                    }
                }
            }
            catch
            {
                // Fallback
                return Environment.GetEnvironmentVariable("PROCESSOR_IDENTIFIER") ?? "Unknown";
            }

            return "Unknown";
        }

        private string GetMacAddress()
        {
            try
            {
                var nic = NetworkInterface.GetAllNetworkInterfaces()
                    .FirstOrDefault(n => n.OperationalStatus == OperationalStatus.Up &&
                                        n.NetworkInterfaceType != NetworkInterfaceType.Loopback);

                return nic?.GetPhysicalAddress().ToString() ?? "000000000000";
            }
            catch
            {
                return "000000000000";
            }
        }

        private string GetDiskSerial()
        {
            try
            {
                using (var searcher = new ManagementObjectSearcher("SELECT SerialNumber FROM Win32_DiskDrive"))
                {
                    foreach (var obj in searcher.Get())
                    {
                        var serial = obj["SerialNumber"]?.ToString()?.Trim();
                        if (!string.IsNullOrEmpty(serial))
                            return serial;
                    }
                }
            }
            catch { }

            return "Unknown";
        }

        private string GetMotherboardId()
        {
            try
            {
                using (var searcher = new ManagementObjectSearcher("SELECT SerialNumber FROM Win32_BaseBoard"))
                {
                    foreach (var obj in searcher.Get())
                    {
                        return obj["SerialNumber"]?.ToString() ?? "Unknown";
                    }
                }
            }
            catch { }

            return "Unknown";
        }

        private string GetBiosSerial()
        {
            try
            {
                using (var searcher = new ManagementObjectSearcher("SELECT SerialNumber FROM Win32_BIOS"))
                {
                    foreach (var obj in searcher.Get())
                    {
                        return obj["SerialNumber"]?.ToString() ?? "Unknown";
                    }
                }
            }
            catch { }

            return "Unknown";
        }

        private string GetOsInfo()
        {
            return $"{Environment.OSVersion.Platform} {Environment.OSVersion.Version}";
        }

        private int GetRamSize()
        {
            try
            {
                using (var searcher = new ManagementObjectSearcher("SELECT Capacity FROM Win32_PhysicalMemory"))
                {
                    long totalBytes = 0;
                    foreach (var obj in searcher.Get())
                    {
                        totalBytes += Convert.ToInt64(obj["Capacity"]);
                    }
                    return (int)(totalBytes / (1024 * 1024)); // Convert to MB
                }
            }
            catch
            {
                return 0;
            }
        }

        #endregion

        public void Dispose()
        {
            _httpClient?.Dispose();
            _serverRsa?.Dispose();
            _machineRsa?.Dispose();
        }
    }
}
