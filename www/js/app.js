// c:\Users\9211n\OneDrive\Desktop\Company Project\surpriseville\partners.surpriseville.co.in\www\js\app.js

document.addEventListener('DOMContentLoaded', () => {
  const statusTitle = document.getElementById('status-title');
  const statusDesc = document.getElementById('status-desc');
  const spinner = document.getElementById('spinner');
  const retryBtn = document.getElementById('retry-btn');

  const TARGET_URL = 'https://partners.surpriseville.co.in';

  async function performAuthentication() {
    statusTitle.textContent = "Surprise Ville";
    statusDesc.textContent = "Verifying security credentials...";
    spinner.style.display = 'block';
    retryBtn.style.display = 'none';

    // Wait a brief moment to ensure Capacitor plugins are ready
    await new Promise(resolve => setTimeout(resolve, 500));

    // Check if running inside Capacitor
    if (typeof window !== 'undefined' && window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.NativeBiometric) {
      const { NativeBiometric } = window.Capacitor.Plugins;

      try {
        const checkResult = await NativeBiometric.isAvailable();
        if (checkResult.isAvailable) {
          // Trigger Biometric Verification Prompt
          await NativeBiometric.verifyIdentity({
            reason: "Access the Partners Portal securely",
            title: "Identity Verification Required",
            subtitle: "Verify using fingerprint or face lock",
            description: "Surprise Ville Partners portal requires authorization to continue."
          });
          
          // Success! Redirect to live portal
          statusDesc.textContent = "Identity verified! Loading portal...";
          window.location.href = TARGET_URL;
        } else {
          // Biometrics not available on device (e.g., no lock set up)
          // Proceed to login page directly
          window.location.href = TARGET_URL;
        }
      } catch (err) {
        // Verification failed (user cancelled or incorrect scan)
        statusTitle.textContent = "Access Denied";
        statusDesc.textContent = "Security verification failed. Please try again.";
        spinner.style.display = 'none';
        retryBtn.style.display = 'block';
      }
    } else {
      // If loaded outside native app (e.g. standard browser), redirect immediately
      window.location.href = TARGET_URL;
    }
  }

  retryBtn.addEventListener('click', performAuthentication);

  // Initial call
  performAuthentication();
});
