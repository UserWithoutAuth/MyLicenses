// Program.cs
// Example usage of the C# License Client

using System;
using System.IO;
using System.Threading;
using System.Threading.Tasks;
using LicenseSDK;

namespace MyApp
{
    class Program
    {
        // Embedded server public key (copy from your server)
        private const string SERVER_PUBLIC_KEY = @"-----BEGIN PUBLIC KEY-----
MIICIjANBgkqhkiG9w0BAQEFAAOCAg8AMIICCgKCAgEA...
(Your actual server public key here)
-----END PUBLIC KEY-----";

        private const string LICENSE_SERVER = "https://license.example.com/api/v1";
        private const string LICENSE_KEY = "XXXX-XXXX-XXXX-XXXX"; // Your license key

        private static DateTime _startTime;

        static async Task Main(string[] args)
        {
            Console.WriteLine("==================================");
            Console.WriteLine("  MyApp Pro - Licensed Edition");
            Console.WriteLine("==================================");
            Console.WriteLine();

            _startTime = DateTime.Now;

            try
            {
                using (var client = new LicenseClient(LICENSE_SERVER, SERVER_PUBLIC_KEY))
                {
                    Console.WriteLine($"Hardware Fingerprint: {client.GetHardwareFingerprint()}");
                    Console.WriteLine();

                    // Check and activate license
                    string activationToken = await CheckLicenseAsync(client);

                    if (string.IsNullOrEmpty(activationToken))
                    {
                        Console.WriteLine();
                        Console.WriteLine("❌ License check failed. Application will exit.");
                        Console.WriteLine("Please contact support@example.com for assistance.");
                        Console.ReadKey();
                        return;
                    }

                    Console.WriteLine();
                    Console.WriteLine("✓ All license checks passed!");
                    Console.WriteLine("==================================");
                    Console.WriteLine();

                    // Start heartbeat task
                    var heartbeatTask = Task.Run(async () =>
                    {
                        await HeartbeatLoopAsync(client, LICENSE_KEY, activationToken);
                    });

                    // Your application code here
                    Console.WriteLine("Application running...");
                    Console.WriteLine("Press any key to exit");

                    // Simulate application running
                    await RunApplicationAsync();

                    Console.ReadKey();

                    // Deactivate on exit
                    Console.WriteLine("Deactivating license...");
                    bool deactivated = await client.DeactivateAsync(LICENSE_KEY, activationToken);
                    if (deactivated)
                    {
                        Console.WriteLine("✓ License deactivated successfully");
                    }
                }
            }
            catch (Exception ex)
            {
                Console.WriteLine($"Error: {ex.Message}");
                Console.ReadKey();
            }
        }

        /// <summary>
        /// Check and activate license
        /// </summary>
        private static async Task<string> CheckLicenseAsync(LicenseClient client)
        {
            Console.WriteLine("Checking license...");

            string activationToken = null;

            // Try to load existing license file
            try
            {
                if (File.Exists("license.lic"))
                {
                    string licenseFile = client.LoadLicenseFile("license.lic");

                    // Verify signature
                    if (!client.VerifyLicenseSignature(licenseFile))
                    {
                        Console.WriteLine("❌ License file signature invalid!");
                        return null;
                    }

                    Console.WriteLine("✓ License file found and verified");

                    // Load activation token
                    if (File.Exists("activation.token"))
                    {
                        activationToken = File.ReadAllText("activation.token").Trim();
                    }
                }
            }
            catch (Exception ex)
            {
                Console.WriteLine($"No existing license file found: {ex.Message}");
            }

            // If no existing license, activate new one
            if (string.IsNullOrEmpty(activationToken))
            {
                Console.WriteLine("Activating license...");
                var result = await client.ActivateAsync(LICENSE_KEY);

                if (!result.Success)
                {
                    Console.WriteLine($"❌ Activation failed: {result.Error}");
                    return null;
                }

                Console.WriteLine("✓ License activated successfully!");
                Console.WriteLine($"  Activation Token: {result.ActivationToken}");
                Console.WriteLine($"  Expires: {result.ExpiresAt}");

                activationToken = result.ActivationToken;

                // Save activation token
                File.WriteAllText("activation.token", activationToken);
            }

            // Validate the license
            var info = await client.ValidateAsync(LICENSE_KEY, activationToken);

            if (!info.Valid)
            {
                Console.WriteLine("❌ License validation failed!");
                Console.WriteLine($"  Status: {info.Status}");
                return null;
            }

            Console.WriteLine("✓ License is valid");
            Console.WriteLine($"  Product: {info.Product.Name} v{info.Product.Version}");
            Console.WriteLine($"  Expires: {info.ExpiresAt}");
            Console.WriteLine($"  Days Remaining: {info.DaysRemaining}");

            // Check for expiry warnings
            if (info.DaysRemaining <= 7)
            {
                Console.ForegroundColor = ConsoleColor.Yellow;
                Console.WriteLine($"⚠️  WARNING: License expires in {info.DaysRemaining} days!");
                Console.ResetColor();
            }

            return activationToken;
        }

        /// <summary>
        /// Periodic heartbeat loop
        /// </summary>
        private static async Task HeartbeatLoopAsync(LicenseClient client,
                                                     string licenseKey,
                                                     string activationToken)
        {
            const string APP_VERSION = "1.0.0";

            while (true)
            {
                // Wait 5 minutes between heartbeats
                await Task.Delay(TimeSpan.FromMinutes(5));

                try
                {
                    int uptimeSeconds = (int)(DateTime.Now - _startTime).TotalSeconds;

                    Console.WriteLine("Sending heartbeat...");
                    bool success = await client.PingAsync(licenseKey, activationToken,
                                                         APP_VERSION, uptimeSeconds);

                    if (success)
                    {
                        Console.WriteLine("✓ Heartbeat sent");
                    }
                    else
                    {
                        Console.WriteLine("❌ Heartbeat failed");
                    }
                }
                catch (Exception ex)
                {
                    Console.WriteLine($"❌ Heartbeat error: {ex.Message}");
                }
            }
        }

        /// <summary>
        /// Simulate application running
        /// </summary>
        private static async Task RunApplicationAsync()
        {
            // Your application logic here
            // This is just an example

            int counter = 0;
            while (true)
            {
                await Task.Delay(1000);

                // Example: Update status every 10 seconds
                if (counter++ % 10 == 0)
                {
                    Console.WriteLine($"[{DateTime.Now:HH:mm:ss}] Application running... (Press any key to exit)");
                }

                // Check if key was pressed
                if (Console.KeyAvailable)
                {
                    break;
                }
            }
        }
    }
}

/*
 * To build and run this example:
 *
 * 1. Create a new .NET Console project:
 *    dotnet new console -n MyApp
 *    cd MyApp
 *
 * 2. Add required NuGet packages:
 *    dotnet add package System.Management
 *    dotnet add package System.Text.Json
 *
 * 3. Copy LicenseClient.cs and Program.cs to the project folder
 *
 * 4. Update SERVER_PUBLIC_KEY with your actual server public key
 *
 * 5. Build:
 *    dotnet build -c Release
 *
 * 6. Run:
 *    dotnet run
 *
 * For production, consider:
 * - Obfuscating the compiled code
 * - Encrypting the embedded public key
 * - Using certificate pinning for HTTP requests
 * - Implementing anti-tampering measures
 */
