<?php

/**
 * Centralized application status constants.
 *
 * Single source of truth for the status strings used across the portal.
 * All staff / doctor pages, reports and queries MUST reference these
 * constants instead of hardcoding status strings, to prevent the
 * "In Consultation" / "In Progress" / "InConsultation" mismatch that
 * caused queued patients to disappear from the dashboard.
 */

/* ------------------------------------------------------------------
   APPOINTMENT STATUSES (appointments.Status)
------------------------------------------------------------------ */

const APPT_STATUS_PENDING         = 'Pending';
const APPT_STATUS_SCHEDULED       = 'Scheduled';
const APPT_STATUS_CHECKED_IN      = 'Checked In';
const APPT_STATUS_CALLED          = 'Called';
const APPT_STATUS_IN_CONSULTATION = 'In Consultation';
const APPT_STATUS_COMPLETED       = 'Completed';
const APPT_STATUS_CANCELLED       = 'Cancelled';
const APPT_STATUS_NO_SHOW         = 'No Show';

/* ------------------------------------------------------------------
   QUEUE STATUSES (queue.Status)
------------------------------------------------------------------ */

const QUEUE_STATUS_WAITING         = 'Waiting';
const QUEUE_STATUS_CALLED          = 'Called';
const QUEUE_STATUS_IN_CONSULTATION = 'In Consultation';
const QUEUE_STATUS_COMPLETED       = 'Completed';
const QUEUE_STATUS_CANCELLED       = 'Cancelled';
const QUEUE_STATUS_NO_SHOW         = 'No Show';

/* ------------------------------------------------------------------
   CONSULTATION STATUSES (consultations.Status)
------------------------------------------------------------------ */

const CONSULTATION_STATUS_ONGOING     = 'Ongoing';
const CONSULTATION_STATUS_IN_PROGRESS = 'In Progress';
const CONSULTATION_STATUS_COMPLETED   = 'Completed';
const CONSULTATION_STATUS_CANCELLED   = 'Cancelled';
