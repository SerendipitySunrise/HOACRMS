<?php

/**
 * Vital signs helpers: classification (normal / high / low) and BMI.
 *
 * These functions power the abnormal-vitals alert system across the
 * staff vitals-capture page and the doctor consultation screen. Ranges
 * follow standard adult triage reference values.
 */

/**
 * Calculate BMI from weight (kg) and height (cm).
 *
 * @param float|int|null $weightKg
 * @param float|int|null $heightCm
 * @return float|null BMI, or null when inputs are missing / invalid.
 */
function bmi(float|int|null $weightKg, float|int|null $heightCm): ?float
{
    if ($weightKg === null || $heightCm === null || (float)$weightKg <= 0 || (float)$heightCm <= 0) {
        return null;
    }

    $heightM = (float)$heightCm / 100.0;
    $bmi     = (float)$weightKg / ($heightM * $heightM);

    return round($bmi, 1);
}

/**
 * Classify a single vital reading.
 *
 * @param string $key  Vital key (see below).
 * @param mixed  $value Raw value.
 * @return array{value: mixed, unit: string, status: string, note: string}
 */
function classifyVital(string $key, $value): array
{
    $normal = ['value' => $value, 'status' => 'normal', 'note' => ''];

    if ($value === null || $value === '') {
        return $normal;
    }

    switch ($key) {

        case 'blood_pressure':
            $systolic  = null;
            $diastolic = null;
            if (preg_match('/^(\d{2,3})\s*\/\s*(\d{2,3})$/', (string)$value, $m)) {
                $systolic  = (int)$m[1];
                $diastolic = (int)$m[2];
            }
            if ($systolic === null || $diastolic === null) {
                return $normal;
            }
            if ($systolic > 139 || $diastolic > 89) {
                return ['value' => $value, 'status' => 'high', 'note' => 'Hypertension range (>139/89)'];
            }
            if ($systolic < 90 || $diastolic < 60) {
                return ['value' => $value, 'status' => 'low', 'note' => 'Low blood pressure (<90/60)'];
            }
            return $normal;

        case 'temperature':
            $temp = (float)$value;
            if ($temp <= 0) {
                return $normal;
            }
            if ($temp > 37.5) {
                return ['value' => $value, 'status' => 'high', 'note' => 'Fever (>37.5\u00B0C)'];
            }
            if ($temp < 36.1) {
                return ['value' => $value, 'status' => 'low', 'note' => 'Hypothermia (<36.1\u00B0C)'];
            }
            return $normal;

        case 'pulse_rate':
            $pulse = (int)$value;
            if ($pulse > 100) {
                return ['value' => $value, 'status' => 'high', 'note' => 'Tachycardia (>100 bpm)'];
            }
            if ($pulse < 60) {
                return ['value' => $value, 'status' => 'low', 'note' => 'Bradycardia (<60 bpm)'];
            }
            return $normal;

        case 'respiratory_rate':
            $rr = (int)$value;
            if ($rr > 20) {
                return ['value' => $value, 'status' => 'high', 'note' => 'Tachypnea (>20 bpm)'];
            }
            if ($rr < 12) {
                return ['value' => $value, 'status' => 'low', 'note' => 'Bradypnea (<12 bpm)'];
            }
            return $normal;

        case 'oxygen_saturation':
            $spo2 = (int)$value;
            if ($spo2 > 0 && $spo2 < 95) {
                return ['value' => $value, 'status' => 'low', 'note' => 'Hypoxemia (SpO2 <95%)'];
            }
            return $normal;

        case 'bmi':
            $bmiValue = (float)$value;
            if ($bmiValue >= 30) {
                return ['value' => $value, 'status' => 'high', 'note' => 'Obese (BMI \u226530)'];
            }
            if ($bmiValue >= 25) {
                return ['value' => $value, 'status' => 'high', 'note' => 'Overweight (BMI 25-29.9)'];
            }
            if ($bmiValue < 18.5) {
                return ['value' => $value, 'status' => 'low', 'note' => 'Underweight (BMI <18.5)'];
            }
            return $normal;

        default:
            return $normal;
    }
}

/**
 * Classify a full set of vitals and return a labeled, ordered list for display.
 *
 * @param array $vitals Associative array keyed by vital key.
 * @return array<int, array{key: string, label: string, value: mixed, unit: string, status: string, note: string}>
 */
function classifyVitals(array $vitals): array
{
    $definitions = [
        'blood_pressure'     => ['label' => 'Blood Pressure', 'unit' => 'mmHg'],
        'temperature'        => ['label' => 'Temperature',    'unit' => '\u00B0C'],
        'pulse_rate'         => ['label' => 'Pulse',          'unit' => 'bpm'],
        'respiratory_rate'   => ['label' => 'Respiratory Rate', 'unit' => '/min'],
        'oxygen_saturation'  => ['label' => 'Oxygen Saturation', 'unit' => '%'],
        'weight'             => ['label' => 'Weight',         'unit' => 'kg'],
        'height'             => ['label' => 'Height',         'unit' => 'cm'],
    ];

    $items = [];

    foreach ($definitions as $key => $def) {
        $value  = $vitals[$key] ?? null;
        $status = 'normal';
        $note   = '';

        if ($value !== null && $value !== '') {
            $classified = classifyVital($key, $value);
            $status     = $classified['status'];
            $note       = $classified['note'];
        }

        $items[] = [
            'key'   => $key,
            'label' => $def['label'],
            'value' => $value,
            'unit'  => $def['unit'],
            'status' => $status,
            'note'  => $note,
        ];
    }

    // Appended BMI derived from weight + height (not a stored column).
    $bmi = bmi(
        isset($vitals['weight']) ? (float)$vitals['weight'] : null,
        isset($vitals['height']) ? (float)$vitals['height'] : null
    );

    if ($bmi !== null) {
        $classified = classifyVital('bmi', $bmi);
        $items[] = [
            'key'    => 'bmi',
            'label'  => 'BMI',
            'value'  => $bmi,
            'unit'   => 'kg/m\u00B2',
            'status' => $classified['status'],
            'note'   => $classified['note'],
        ];
    }

    return $items;
}

/**
 * Return only the abnormal (high / low) items from a full classification.
 *
 * @param array $vitals Associative array keyed by vital key.
 * @return array<int, array{key: string, label: string, value: mixed, unit: string, status: string, note: string}>
 */
function abnormalVitals(array $vitals): array
{
    $abnormal = [];

    foreach (classifyVitals($vitals) as $item) {
        if ($item['status'] !== 'normal') {
            $abnormal[] = $item;
        }
    }

    return $abnormal;
}

/**
 * Render an inline status pill for a classified vital item.
 *
 * @param array $item A single item from classifyVitals().
 * @return string HTML (escaped).
 */
function vitalStatusBadge(array $item): string
{
    $label = ucfirst($item['status']);

    return '<span class="vital-badge vital-badge-' .
        htmlspecialchars($item['status']) .
        '">' . htmlspecialchars($label) . '</span>';
}
