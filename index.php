<?php
$logoutMessage = isset($_GET['logout']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MediCare — Patient Portal</title>
    <link rel="stylesheet" href="assets/css/landing.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="landing-page">

    <?php if ($logoutMessage): ?>
        <div class="landing-alert">You have been logged out successfully.</div>
    <?php endif; ?>

    <header class="landing-nav" id="landingNav">
        <a href="index.php" class="brand">
            <span>MediCare</span>
        </a>
        <ul class="nav-links">
            <li><a href="#features">Features</a></li>
            <li><a href="#how-it-works">How It Works</a></li>
            <li><a href="#contact">Contact</a></li>
        </ul>
        <div class="nav-actions">
            <a href="auth/login.php" class="nav-signin">Sign In</a>
            <a href="portal-select.php?action=register" class="btn-primary">Get started</a>
        </div>
    </header>

    <section class="landing-hero">
        <div class="hero-content">
            <h1>Your Hospital Experience, <span class="accent">Made Easier</span></h1>
            <p class="hero-sub">
                Book appointments, view your results, and manage your healthcare journey—all in one secure portal.
                Patients, staff, and administrators each have their own sign-in.
            </p>
        </div>
    </section>

    <!-- ========== FEATURES SECTION ========== -->
    <section id="features" class="landing-section features-section">
        <div class="section-container">
            <div class="section-header">
                <div class="section-tag">FEATURES</div>
                <h2 class="section-title">Everything You Need</h2>
                <p class="section-subtitle">All the tools to manage your health, right at your fingertips.</p>
            </div>

            <div class="features-grid">
                <div class="feature-card-new">
                    <div class="feature-icon-mint">
                        <svg viewBox="0 0 24 24" fill="none" stroke="#0D9488" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><path d="M12 14v4M10 16h4"/></svg>
                    </div>
                    <h3>Book Appointments</h3>
                    <p>Schedule appointments with your preferred department and doctor in just a few clicks.</p>
                </div>

                <div class="feature-card-new">
                    <div class="feature-icon-mint">
                        <svg viewBox="0 0 24 24" fill="none" stroke="#0D9488" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 15l2 2 4-4"/></svg>
                    </div>
                    <h3>View Results</h3>
                    <p>Access your lab results, prescriptions, and consultation records anytime, anywhere.</p>
                </div>

                <div class="feature-card-new">
                    <div class="feature-icon-mint">
                        <svg viewBox="0 0 24 24" fill="none" stroke="#0D9488" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                    </div>
                    <h3>Stay Notified</h3>
                    <p>Receive real-time updates on your appointments, queue status, and health reminders.</p>
                </div>

                <div class="feature-card-new">
                    <div class="feature-icon-mint">
                        <svg viewBox="0 0 24 24" fill="none" stroke="#0D9488" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </div>
                    <h3>Check-in & Queue</h3>
                    <p>Check in online and track your queue position in real time—no more waiting blindly.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- ========== HOW IT WORKS SECTION ========== -->
    <section id="how-it-works" class="landing-section process-section">
        <div class="section-container">
            <div class="section-header">
                <div class="section-tag">PROCESS</div>
                <h2 class="section-title">How It Works</h2>
                <p class="section-subtitle">Four simple steps to take control of your healthcare experience.</p>
            </div>

            <div class="steps-grid">
                <div class="step-card">
                    <div class="step-number">1</div>
                    <h3>Create Your Account</h3>
                    <p>Sign up as a patient and complete your profile with your basic health information.</p>
                </div>

                <div class="step-card">
                    <div class="step-number">2</div>
                    <h3>Book an Appointment</h3>
                    <p>Choose a department, pick a date and time, and confirm your appointment instantly.</p>
                </div>

                <div class="step-card">
                    <div class="step-number">3</div>
                    <h3>Check In & Wait</h3>
                    <p>Arrive at the hospital, check in, and track your queue position in real time.</p>
                </div>

                <div class="step-card">
                    <div class="step-number">4</div>
                    <h3>View Your Results</h3>
                    <p>After your consultation, access your prescriptions, lab results, and records online.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- ========== CTA BANNER ========== -->
    <section id="cta" class="landing-section cta-banner">
        <div class="section-container cta-container">
            <h2>Ready to Get Started?</h2>
            <p>Join thousands of patients who manage their healthcare with ease. Create your account today.</p>
            <div class="cta-buttons">
                <a href="portal-select.php?action=register" class="cta-btn-primary">Create Account</a>
                <a href="auth/login.php" class="cta-btn-secondary">Sign In</a>
            </div>
        </div>
    </section>

    <!-- ========== CONTACT SECTION ========== -->
    <section id="contact" class="landing-section contact-section">
        <div class="section-container">
            <div class="contact-grid">

                <div class="contact-left">
                    <div class="section-tag">CONTACT</div>
                    <h2 class="section-title" style="text-align:left;">Need Help?</h2>
                    <p class="contact-intro">Our support team is available to assist you with any questions about using the patient portal.</p>

                    <div class="contact-info-list">
                        <div class="contact-info-item">
                            <div class="contact-icon-circle">
                                <svg viewBox="0 0 24 24" fill="none" stroke="#0D9488" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                            </div>
                            <div>
                                <div class="contact-info-title">Phone Support</div>
                                <div class="contact-info-detail">+1 (555) 123-4567</div>
                            </div>
                        </div>

                        <div class="contact-info-item">
                            <div class="contact-icon-circle">
                                <svg viewBox="0 0 24 24" fill="none" stroke="#0D9488" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                            </div>
                            <div>
                                <div class="contact-info-title">Email Support</div>
                                <div class="contact-info-detail">support@medicareportal.com</div>
                            </div>
                        </div>

                        <div class="contact-info-item">
                            <div class="contact-icon-circle">
                                <svg viewBox="0 0 24 24" fill="none" stroke="#0D9488" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            </div>
                            <div>
                                <div class="contact-info-title">Support Hours</div>
                                <div class="contact-info-detail">Mon — Fri, 8:00 AM — 6:00 PM</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="contact-right">
                    <div class="contact-image-card">
                        <img src="https://images.unsplash.com/photo-1519494026892-80bbd2d6fd0d?w=600&h=400&fit=crop" alt="Hospital Reception">
                    </div>
                </div>

            </div>
        </div>
    </section>

    <footer class="landing-footer">
        &copy; <?php echo date('Y'); ?> MediCare Outpatient Portal
    </footer>

    <script>
        // Give the fixed navbar a solid background once the user scrolls
        // past the hero, so white nav text never blends into light section
        // backgrounds (part of the header-overlap fix).
        (function () {
            var nav = document.getElementById('landingNav');
            if (!nav) return;
            function onScroll() {
                if (window.scrollY > window.innerHeight * 0.7) {
                    nav.classList.add('is-scrolled');
                } else {
                    nav.classList.remove('is-scrolled');
                }
            }
            window.addEventListener('scroll', onScroll, { passive: true });
            onScroll();
        })();

        // Auto-hide the alert after 4 seconds
        (function () {
            var alert = document.querySelector('.landing-alert');
            if (!alert) return;
            setTimeout(function () {
                alert.classList.add('hide');
            }, 4000);
        })();
    </script>

</body>
</html>