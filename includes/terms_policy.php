<?php
/**
 * Shared Terms and Conditions & Data Privacy Policy consent partial.
 * Provides:
 *   - renderTermsCheckbox(): mandatory consent checkbox with a clickable policy link
 *   - renderTermsModal():       modal displaying the full policy; the I Agree button auto-checks the consent checkbox
 */

if (!function_exists('renderTermsCheckbox')) {
    function renderTermsCheckbox(): void
    {
        ?>
        <div class="form-options">
            <label class="form-check-label terms-consent">
                <input type="checkbox" name="agree_terms" id="agree_terms" required>
                <span>I agree to the <a href="#" class="terms-link" onclick="openTermsModal(event); return false;">Terms and Conditions &amp; Data Privacy Policy</a>. I consent to the collection and processing of my personal and medical information for outpatient healthcare management purposes.</span>
            </label>
            <div id="terms-error" class="terms-error" hidden></div>
        </div>
        <?php
    }
}

if (!function_exists('renderTermsModal')) {
    function renderTermsModal(): void
    {
        ?>
        <div class="terms-modal-overlay" id="termsModal" onclick="if(event.target===this)closeTermsModal()" aria-hidden="true">
            <div class="terms-modal" role="dialog" aria-modal="true" aria-labelledby="terms-modal-title">
                <div class="terms-modal-header">
                    <h3 id="terms-modal-title">Terms and Conditions &amp; Data Privacy Policy</h3>
                    <button type="button" class="terms-modal-close" onclick="closeTermsModal()" aria-label="Close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="terms-modal-body">
                    <p class="terms-last-updated">Last updated: May 10, 2026</p>
                    <ol class="terms-policy-list">
                        <li>
                            <strong>System Purpose</strong>
                            <p>By using the Hospital Outpatient Appointment and Consultation Record Management System (HOACRMS), users acknowledge that the system is designed solely for outpatient healthcare management, including appointment scheduling, patient record management, and clinical consultation tracking.</p>
                        </li>
                        <li>
                            <strong>Data Collection &amp; Processing</strong>
                            <p>The system collects and processes personal and medical information solely for outpatient healthcare management purposes. This includes but is not limited to full name, contact information, date of birth, medical history, consultation notes, and appointment records. All data is used strictly within the hospital's internal healthcare operations.</p>
                        </li>
                        <li>
                            <strong>Confidentiality &amp; Legal Protection</strong>
                            <p>All patient records are treated as confidential and protected under the Data Privacy Act of 2012 (Republic Act No. 10173). The hospital implements physical, technical, and organizational security measures to protect personal data against unauthorized access, alteration, disclosure, or destruction.</p>
                        </li>
                        <li>
                            <strong>Authorized Access</strong>
                            <p>Only authorized users such as doctors, staff, and administrators may access patient information based on role permissions. Each user role has carefully defined access levels to ensure data is only visible to those with a legitimate clinical or administrative need.</p>
                        </li>
                        <li>
                            <strong>Patient Responsibilities</strong>
                            <p>Patients are responsible for providing accurate and updated personal and medical information. Inaccurate or outdated information may affect the quality of care and appointment management. Patients must notify the hospital immediately of any changes to their personal details.</p>
                        </li>
                        <li>
                            <strong>Appointment Rescheduling &amp; Cancellation</strong>
                            <p>The hospital reserves the right to reschedule or cancel appointments in cases of emergencies, doctor unavailability, or system maintenance. Patients will be notified as promptly as possible via the contact information on file. Patients may also request rescheduling through the system or by contacting the hospital directly.</p>
                        </li>
                        <li>
                            <strong>Prohibited Activities</strong>
                            <p>Unauthorized access, misuse, alteration, or sharing of patient information is strictly prohibited. Any user found violating these terms may face immediate suspension or termination of system access, and may be subject to legal action under applicable Philippine laws.</p>
                        </li>
                        <li>
                            <strong>Audit &amp; Accountability</strong>
                            <p>Audit trails and activity logs are maintained for accountability and monitoring purposes. The system records user actions including logins, record views, edits, and deletions to ensure transparency and to facilitate investigations if necessary.</p>
                        </li>
                        <li>
                            <strong>Consent</strong>
                            <p>By registering an account and using this system, users provide explicit consent for the storage and processing of their information within the HOACRMS. This consent covers all activities described in these terms, including data collection, processing, and sharing with authorized hospital personnel as necessary for healthcare delivery.</p>
                        </li>
                        <li>
                            <strong>Contact &amp; Inquiries</strong>
                            <p>For questions about these terms, your personal data, or to exercise your rights under the Data Privacy Act of 2012, please contact the hospital's Data Protection Officer or visit the hospital's main office during operating hours.</p>
                        </li>
                    </ol>
                </div>
                <div class="terms-modal-footer">
                    <button type="button" class="terms-modal-agree" onclick="onAgreeTerms()">I Agree</button>
                </div>
            </div>
        </div>

        <script>
            // Terms & Privacy consent modal
            function openTermsModal(event) {
                if (event && event.preventDefault) event.preventDefault();

                var modal = document.getElementById('termsModal');
                if (modal) modal.classList.add('open');
                document.body.classList.add('terms-modal-open');
            }

            function closeTermsModal() {
                var modal = document.getElementById('termsModal');
                if (modal) modal.classList.remove('open');
                document.body.classList.remove('terms-modal-open');
            }

            function onAgreeTerms() {
                var agree = document.getElementById('agree_terms');
                if (agree) {
                    agree.checked = true;
                    var err = document.getElementById('terms-error');
                    if (err) {
                        err.hidden = true;
                        err.textContent = '';
                    }
                    var label = agree.closest('label');
                    if (label) label.classList.remove('terms-invalid');
                }
                closeTermsModal();
            }

            document.addEventListener('DOMContentLoaded', function() {
                var modal = document.getElementById('termsModal');
                var agree = document.getElementById('agree_terms');

                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape' && modal && modal.classList.contains('open')) {
                        closeTermsModal();
                    }
                });

                var formEl = document.querySelector('form');
                if (formEl && agree) {
                    formEl.addEventListener('submit', function(e) {
                        if (!agree.checked) {
                            e.preventDefault();
                            if (e.stopImmediatePropagation) e.stopImmediatePropagation();

                            var err = document.getElementById('terms-error');
                            if (err) {
                                err.textContent = 'Please accept the Terms and Conditions & Data Privacy Policy to continue.';
                                err.hidden = false;
                            }
                            agree.closest('label').classList.add('terms-invalid');
                            agree.focus();
                        }
                    });

                    agree.addEventListener('change', function() {
                        var err = document.getElementById('terms-error');
                        if (err) {
                            err.hidden = true;
                            err.textContent = '';
                        }
                        agree.closest('label').classList.remove('terms-invalid');
                    });
                }
            });
        </script>
        <?php
    }
}