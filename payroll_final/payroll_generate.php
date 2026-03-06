<?php
/**
 * Payroll System - Generate Payroll for All Departments
 * Bulk payroll generation across multiple departments
 */

require_once 'includes/config.php';
require_once 'includes/auth.php';
requireSuperAdmin();

$pageTitle = 'Generate Payroll';

// Handle batch generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_payroll'])) {
    $payrollMonth  = sanitize($_POST['payroll_month']);
    $payrollYear   = (int)$_POST['payroll_year'];
    $periodType    = sanitize($_POST['period_type']);
    $payrollPeriod = $payrollMonth . ' ' . $periodType . ', ' . $payrollYear;

    $selectedDepartments = $_POST['departments'] ?? [];

    // PERA limit
    $isHalfPeriod = ($periodType === '1-15' || str_starts_with($periodType, '16-'));
    $peraLimit    = $isHalfPeriod ? 1000.00 : 2000.00;
    $defaultPera  = min((float)($_POST['default_pera'] ?? $peraLimit), $peraLimit);

    // BCGEU: auto-calculated
    $bcgeu = $isHalfPeriod ? 50.00 : 100.00;

    // Other
    $other_deductions = (float)($_POST['other_deductions'] ?? 0);

    // Provident sub-fields
    $provident_fund      = (float)($_POST['provident_fund']      ?? 0);
    $provident_fund_loan = (float)($_POST['provident_fund_loan'] ?? 0);
    $provident_edu_loan  = (float)($_POST['provident_edu_loan']  ?? 0);
    $provident_term_loan = (float)($_POST['provident_term_loan'] ?? 0);
    $provident = $provident_fund + $provident_fund_loan + $provident_edu_loan + $provident_term_loan;

    // Pag-IBIG sub-fields
    $pagibig_multi     = (float)($_POST['pagibig_multi']     ?? 0);
    $pagibig_emergency = (float)($_POST['pagibig_emergency'] ?? 0);
    $pagibig_premium   = (float)($_POST['pagibig_premium']   ?? 0);
    $pagibig_mp2       = (float)($_POST['pagibig_mp2']       ?? 0);
    $pagibig_housing   = (float)($_POST['pagibig_housing']   ?? 0);
    $pagibig_extra = $pagibig_multi + $pagibig_emergency + $pagibig_premium + $pagibig_mp2 + $pagibig_housing;

    // GSIS sub-fields
    $gsis_life_ret    = (float)($_POST['gsis_life_ret']    ?? 0);
    $gsis_emergency   = (float)($_POST['gsis_emergency']   ?? 0);
    $gsis_cpl         = (float)($_POST['gsis_cpl']         ?? 0);
    $gsis_gpal        = (float)($_POST['gsis_gpal']        ?? 0);
    $gsis_mpl         = (float)($_POST['gsis_mpl']         ?? 0);
    $gsis_mpl_lite    = (float)($_POST['gsis_mpl_lite']    ?? 0);
    $gsis_policy_loan = (float)($_POST['gsis_policy_loan'] ?? 0);
    $gsis_extra = $gsis_life_ret + $gsis_emergency + $gsis_cpl + $gsis_gpal + $gsis_mpl + $gsis_mpl_lite + $gsis_policy_loan;

    // BACGEM sub-fields
    $bacgem_edu_loan = (float)($_POST['bacgem_edu_loan'] ?? 0);
    $bacgem_grocery  = (float)($_POST['bacgem_grocery']  ?? 0);
    $bacgem_others   = (float)($_POST['bacgem_others']   ?? 0);
    $bacgem_hcp      = (float)($_POST['bacgem_hcp']      ?? 0);
    $bacgem_loan     = (float)($_POST['bacgem_loan']     ?? 0);
    $bacgem = $bacgem_edu_loan + $bacgem_grocery + $bacgem_others + $bacgem_hcp + $bacgem_loan;

    // NOCGEM sub-fields
    $nocgem_edu_loan     = (float)($_POST['nocgem_edu_loan']     ?? 0);
    $nocgem_emergency    = (float)($_POST['nocgem_emergency']    ?? 0);
    $nocgem_grocery      = (float)($_POST['nocgem_grocery']      ?? 0);
    $nocgem_hospital     = (float)($_POST['nocgem_hospital']     ?? 0);
    $nocgem_others       = (float)($_POST['nocgem_others']       ?? 0);
    $nocgem_plp          = (float)($_POST['nocgem_plp']          ?? 0);
    $nocgem_regular_loan = (float)($_POST['nocgem_regular_loan'] ?? 0);
    $nocgem = $nocgem_edu_loan + $nocgem_emergency + $nocgem_grocery + $nocgem_hospital + $nocgem_others + $nocgem_plp + $nocgem_regular_loan;

    $totalSuccess = 0; $totalSkipped = 0; $totalErrors = 0;
    $departmentResults = [];

    foreach ($selectedDepartments as $deptId) {
        $deptId = (int)$deptId;
        $deptStmt = $conn->prepare("SELECT department_name FROM departments WHERE id = ?");
        $deptStmt->bind_param("i", $deptId);
        $deptStmt->execute();
        $deptName = $deptStmt->get_result()->fetch_assoc()['department_name'];
        $deptStmt->close();

        $deptSuccess = 0; $deptSkipped = 0; $deptErrors = 0;

        $empStmt = $conn->prepare("
            SELECT e.id, e.employee_id, e.first_name, e.last_name, e.date_hired, p.salary_grade, p.basic_salary
            FROM employees e LEFT JOIN positions p ON e.position_id = p.id
            WHERE e.department_id = ? AND e.is_active = 1
        ");
        $empStmt->bind_param("i", $deptId);
        $empStmt->execute();
        $employees = $empStmt->get_result();

        while ($emp = $employees->fetch_assoc()) {
            $empId = $emp['id'];
            $checkStmt = $conn->prepare("SELECT id FROM payroll WHERE employee_id = ? AND payroll_month = ? AND payroll_year = ? AND period_type = ?");
            $checkStmt->bind_param("isis", $empId, $payrollMonth, $payrollYear, $periodType);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0) { $deptSkipped++; $checkStmt->close(); continue; }
            $checkStmt->close();

            $currentStep = 1;
            if ($emp['date_hired']) {
                $hireDate = new DateTime($emp['date_hired']);
                $yearsOfService = $hireDate->diff(new DateTime())->y;
                $currentStep = min(8, floor($yearsOfService / 3) + 1);
            }

            $basicSalary = $emp['basic_salary'] ?? 0;
            $salaryId = 0;
            if ($emp['salary_grade']) {
                $sq = $conn->prepare("SELECT salary_id, salary_rate FROM salary WHERE salary_grade = ? AND step_no = ? LIMIT 1");
                $sq->bind_param("si", $emp['salary_grade'], $currentStep);
                $sq->execute();
                if ($sd = $sq->get_result()->fetch_assoc()) { $basicSalary = $sd['salary_rate']; $salaryId = $sd['salary_id']; }
                $sq->close();
            }

            $pera       = $defaultPera;
            $gsis       = 0;
            $philhealth = calculatePhilHealth($basicSalary);
            $pagibig    = calculatePagIbig($basicSalary);
            $wtax       = calculateWithholdingTax($basicSalary);
            $grossPay   = $basicSalary + $pera;
            $totalDeductions = $gsis + $gsis_extra + $philhealth + $pagibig + $pagibig_extra
                             + $wtax + $provident + $bcgeu + $nocgem + $bacgem + $other_deductions;
            $netPay = $grossPay - $totalDeductions;
            $status = 'Draft';

            $stmt = $conn->prepare("
                INSERT INTO payroll (
                    employee_id, department_id, salary_id, payroll_period, payroll_month, payroll_year, period_type,
                    basic_salary, pera, gross_pay, wtax, philhealth, gsis, pagibig, provident, bcgeu, nocgem, bacgem,
                    other_deductions, total_deductions, net_pay, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                "iiissisdddddddddddddds",
                $empId, $deptId, $salaryId, $payrollPeriod, $payrollMonth, $payrollYear, $periodType,
                $basicSalary, $pera, $grossPay, $wtax, $philhealth, $gsis, $pagibig, $provident, $bcgeu,
                $nocgem, $bacgem, $other_deductions, $totalDeductions, $netPay, $status
            );
            if ($stmt->execute()) { $deptSuccess++; } else { $deptErrors++; }
            $stmt->close();
        }
        $empStmt->close();
        $totalSuccess += $deptSuccess; $totalSkipped += $deptSkipped; $totalErrors += $deptErrors;
        $departmentResults[] = ['name' => $deptName, 'success' => $deptSuccess, 'skipped' => $deptSkipped, 'errors' => $deptErrors];
    }

    $message  = "Payroll Generation Complete!<br><strong>Total: $totalSuccess created, $totalSkipped skipped, $totalErrors errors</strong><br><br><strong>Breakdown by Department:</strong><br>";
    foreach ($departmentResults as $r) {
        $message .= "• {$r['name']}: {$r['success']} created" . ($r['skipped'] > 0 ? ", {$r['skipped']} skipped" : "") . ($r['errors'] > 0 ? ", {$r['errors']} errors" : "") . "<br>";
    }
    if ($totalSuccess > 0) { $_SESSION['success_message'] = $message; }
    else { $_SESSION['error_message'] = "No payroll records were created. " . ($totalSkipped > 0 ? "$totalSkipped already exist. " : "") . ($totalErrors > 0 ? "$totalErrors failed." : ""); }
    header('Location: payroll_generate.php'); exit;
}

$departmentsQuery = $conn->query("
    SELECT d.*, COUNT(e.id) as employee_count, COUNT(CASE WHEN e.is_active = 1 THEN 1 END) as active_count
    FROM departments d LEFT JOIN employees e ON d.id = e.department_id
    GROUP BY d.id ORDER BY d.department_name ASC
");
$departments = [];
while ($dept = $departmentsQuery->fetch_assoc()) { $departments[] = $dept; }

require_once 'includes/header.php';
?>

<style>
.generate-header {
    background: linear-gradient(135deg, #059669, #047857);
    color:white; padding:2rem; border-radius:16px; margin-bottom:2rem;
    position:relative; overflow:hidden;
}
.generate-header::before {
    content:''; position:absolute; top:-50%; right:-10%;
    width:400px; height:400px;
    background:radial-gradient(circle, rgba(255,255,255,.15) 0%, transparent 70%);
    border-radius:50%;
}
.generate-header-content { position:relative; z-index:1; }
.generate-header h1 { font-size:2rem; font-weight:800; margin-bottom:.5rem; display:flex; align-items:center; gap:1rem; }
.generate-header p  { font-size:1.125rem; opacity:.95; }

.period-config-card { background:#fff; border-radius:16px; padding:2rem; margin-bottom:2rem; box-shadow:0 4px 6px -1px rgba(0,0,0,.1); }
.period-config-header { display:flex; align-items:center; gap:.75rem; margin-bottom:1.5rem; }
.period-config-header h2 { font-size:1.5rem; font-weight:700; color:#111827; }
.period-config-header i  { color:#059669; font-size:1.75rem; }

.period-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:1.5rem; margin-bottom:2rem; }
.period-field label { display:block; font-weight:700; color:#374151; margin-bottom:.5rem; font-size:.875rem; }
.period-field select, .period-field input {
    width:100%; padding:12px 16px; border:2px solid #e5e7eb; border-radius:10px;
    font-size:1rem; font-weight:600; color:#111827; transition:all .2s;
}
.period-field select:focus, .period-field input:focus { outline:none; border-color:#059669; box-shadow:0 0 0 3px rgba(5,150,105,.1); }
.pera-limit-hint { font-size:.75rem; font-weight:700; color:#92400e; background:#fef3c7; padding:3px 10px; border-radius:10px; display:inline-block; margin-top:6px; }

/* Deductions */
.deductions-section { background:#f9fafb; border-radius:12px; padding:1.5rem; margin-top:1.5rem; }
.deductions-section > h3 { font-size:1.125rem; font-weight:700; color:#111827; margin-bottom:.35rem; }
.deductions-section > p  { color:#6b7280; font-size:.875rem; margin-bottom:1.25rem; }

.bcgeu-notice {
    display:flex; align-items:center; gap:.75rem;
    background:#ede9fe; border:1.5px solid #c4b5fd; border-radius:10px;
    padding:.75rem 1.1rem; margin-bottom:1rem;
    font-size:.85rem; font-weight:600; color:#5b21b6;
}
.bcgeu-notice i { font-size:1.1rem; }

/* Expandable blocks */
.coop-block { border:2px solid #e5e7eb; border-radius:12px; overflow:hidden; margin-bottom:.75rem; transition:border-color .2s; }
.coop-block:last-child { margin-bottom:0; }
.coop-block:focus-within { border-color:#059669; }

.coop-block-header {
    display:flex; align-items:center; justify-content:space-between;
    padding:13px 18px; background:#f8fafc; cursor:pointer; user-select:none; transition:background .15s;
}
.coop-block-header:hover { background:#eef4fb; }
.coop-block-title { display:flex; align-items:center; gap:12px; }

.coop-tag { display:inline-block; padding:4px 12px; border-radius:6px; font-size:.72rem; font-weight:800; letter-spacing:.05em; white-space:nowrap; }
.tag-other     { background:#fee2e2; color:#991b1b; }
.tag-provident { background:#ede9fe; color:#5b21b6; }
.tag-pagibig   { background:#fce7f3; color:#9d174d; }
.tag-gsis      { background:#dcfce7; color:#14532d; }
.tag-bacgem    { background:#dbeafe; color:#1e40af; }
.tag-nocgem    { background:#d1fae5; color:#065f46; }

.coop-label { font-size:.875rem; color:#374151; font-weight:600; }
.coop-block-right { display:flex; align-items:center; gap:12px; }
.coop-total { font-size:.85rem; font-weight:700; color:#374151; background:#e5e7eb; padding:4px 14px; border-radius:20px; white-space:nowrap; }
.coop-chevron { color:#6b7280; transition:transform .25s; font-size:.85rem; }
.coop-chevron.open { transform:rotate(180deg); }

.coop-block-body { display:none; padding:16px 18px; background:#fff; border-top:1px solid #e5e7eb; animation:slideDown .2s ease; }
.coop-block-body.open { display:block; }
@keyframes slideDown { from{opacity:0;transform:translateY(-5px)} to{opacity:1;transform:translateY(0)} }

.coop-subfields { display:grid; gap:.85rem; }
.coop-subfield-item label { display:block; font-size:.78rem; font-weight:600; color:#374151; margin-bottom:4px; }
.coop-subfield-item input {
    width:100%; padding:8px 10px; border:2px solid #e5e7eb; border-radius:8px;
    text-align:right; font-size:.875rem; transition:border-color .15s;
}
.coop-subfield-item input:focus { outline:none; border-color:#059669; }
.coop-note { font-size:.75rem; color:#9ca3af; margin-bottom:10px; display:flex; align-items:center; gap:6px; }

/* Departments */
.departments-selection-card { background:#fff; border-radius:16px; padding:2rem; margin-bottom:2rem; box-shadow:0 4px 6px -1px rgba(0,0,0,.1); }
.departments-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; }
.departments-header h2 { font-size:1.5rem; font-weight:700; color:#111827; display:flex; align-items:center; gap:.75rem; }
.departments-header i { color:#059669; }
.select-all-button { display:inline-flex; align-items:center; gap:.5rem; padding:10px 20px; background:#f3f4f6; border:2px solid #e5e7eb; border-radius:8px; font-weight:600; cursor:pointer; transition:all .2s; }
.select-all-button:hover { background:#e5e7eb; }
.departments-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:1rem; }
.department-checkbox-card { background:#f9fafb; border:3px solid #e5e7eb; border-radius:12px; padding:1.25rem; cursor:pointer; transition:all .2s; display:flex; align-items:center; gap:1rem; }
.department-checkbox-card:hover { background:#f3f4f6; border-color:#d1d5db; }
.department-checkbox-card.selected { background:#d1fae5; border-color:#10b981; }
.department-checkbox-card input[type="checkbox"] { width:24px; height:24px; cursor:pointer; accent-color:#059669; }
.department-info h3 { font-size:1rem; font-weight:700; color:#111827; margin-bottom:.25rem; }
.department-badge { background:#e0f2fe; color:#0369a1; padding:4px 10px; border-radius:6px; font-size:.75rem; font-weight:700; }
.employee-count { display:flex; align-items:center; gap:.4rem; font-weight:600; color:#059669; font-size:.875rem; }

/* Summary bar */
.generate-summary {
    background:linear-gradient(135deg, #059669, #047857);
    color:white; border-radius:16px; padding:2rem;
    display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1.5rem; margin-bottom:2rem;
}
.summary-info h3 { font-size:1.5rem; font-weight:800; margin-bottom:.35rem; }
.summary-info p  { opacity:.9; font-size:.95rem; }
.btn-generate {
    background:white; color:#059669; padding:16px 40px; border-radius:12px;
    font-weight:800; font-size:1.15rem; border:none; cursor:pointer;
    display:inline-flex; align-items:center; gap:10px; transition:all .2s; letter-spacing:.3px;
}
.btn-generate:hover:not(:disabled) { transform:translateY(-2px); box-shadow:0 10px 25px -5px rgba(0,0,0,.3); }
.btn-generate:disabled { opacity:.5; cursor:not-allowed; }
</style>

<div class="page-header">
    <div class="breadcrumb">
        <a href="index.php"><i class="fas fa-home"></i></a>
        <span>/</span>
        <a href="payroll.php">Payroll</a>
        <span>/</span>
        <span>Generate Payroll</span>
    </div>
</div>

<?php if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success"><i class="alert-icon fas fa-check-circle"></i><div class="alert-content"><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></div></div>
<?php endif; ?>
<?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-danger"><i class="alert-icon fas fa-exclamation-circle"></i><div class="alert-content"><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?></div></div>
<?php endif; ?>

<div class="generate-header">
    <div class="generate-header-content">
        <h1><i class="fas fa-calculator"></i> Generate Payroll</h1>
        <p>Create payroll records for multiple departments in one operation</p>
    </div>
</div>

<?php if (!empty($departments)): ?>
<form method="POST" id="generateForm">
    <input type="hidden" name="generate_payroll" value="1">

    <!-- Period Config -->
    <div class="period-config-card">
        <div class="period-config-header">
            <i class="fas fa-calendar-alt"></i>
            <h2>Payroll Period Configuration</h2>
        </div>
        <div class="period-grid">
            <div class="period-field">
                <label><i class="fas fa-calendar"></i> Month</label>
                <select name="payroll_month" id="payroll_month" required onchange="updatePeriodOptions()">
                    <?php $months=['January','February','March','April','May','June','July','August','September','October','November','December']; $cm=date('F');
                    foreach($months as $m): ?><option value="<?php echo $m;?>" <?php echo $m===$cm?'selected':'';?>><?php echo $m;?></option><?php endforeach;?>
                </select>
            </div>
            <div class="period-field">
                <label><i class="fas fa-hashtag"></i> Year</label>
                <select name="payroll_year" id="payroll_year" required onchange="updatePeriodOptions()">
                    <?php $cy=date('Y'); for($y=$cy+1;$y>=$cy-2;$y--): ?><option value="<?php echo $y;?>" <?php echo $y==$cy?'selected':'';?>><?php echo $y;?></option><?php endfor;?>
                </select>
            </div>
            <div class="period-field">
                <label><i class="fas fa-clock"></i> Period Type</label>
                <select name="period_type" id="period_type" required onchange="updatePeriodOptions()">
                    <option value="1-15">1-15 (First Half)</option>
                    <option value="16-31">16-31 (Second Half)</option>
                    <option value="1-31">1-31 (Full Month)</option>
                </select>
            </div>
            <div class="period-field">
                <label><i class="fas fa-money-bill-wave"></i> Default PERA</label>
                <input type="number" name="default_pera" id="default_pera" value="1000.00" step="0.01" min="0" max="1000" required>
                <span class="pera-limit-hint" id="pera-limit-hint">Max ₱1,000 for this period</span>
            </div>
        </div>

        <!-- Deductions -->
        <div class="deductions-section">
            <h3><i class="fas fa-minus-circle"></i> Additional Deductions (Applied to All Employees)</h3>
            <p>Note: PhilHealth, Pag-IBIG, and Withholding Tax are calculated automatically. All values below are saved to the <strong>consolidated</strong> table.</p>

            <div class="bcgeu-notice">
                <i class="fas fa-bolt"></i>
                <span>BCGEU — ₱100.00/month is automatically deducted: <strong>₱50.00</strong> per half-period (1–15 or 16–31), <strong>₱100.00</strong> for full-month payroll.</span>
            </div>

            <!-- OTHER CONSOLIDATED -->
            <div class="coop-block">
                <div class="coop-block-header" onclick="toggleBlock('other')">
                    <div class="coop-block-title">
                        <span class="coop-tag tag-other">OTHER CONSOLIDATED</span>
                        <span class="coop-label">Miscellaneous &amp; Other Deductions</span>
                    </div>
                    <div class="coop-block-right">
                        <span class="coop-total" id="other-total-display">Total: ₱0.00</span>
                        <i class="fas fa-chevron-down coop-chevron" id="other-chevron"></i>
                    </div>
                </div>
                <div class="coop-block-body" id="other-body">
                    <div class="coop-subfields" style="grid-template-columns:repeat(2,1fr);">
                        <div class="coop-subfield-item">
                            <label>Other Deductions</label>
                            <input type="number" name="other_deductions" class="other-sub" value="0.00" step="0.01" min="0" oninput="updateTotal('other')">
                        </div>
                    </div>
                </div>
            </div>

            <!-- PROVIDENT CONSOLIDATED -->
            <div class="coop-block">
                <div class="coop-block-header" onclick="toggleBlock('provident')">
                    <div class="coop-block-title">
                        <span class="coop-tag tag-provident">PROVIDENT CONSOLIDATED</span>
                        <span class="coop-label">Provident Fund Contributions &amp; Loans</span>
                    </div>
                    <div class="coop-block-right">
                        <span class="coop-total" id="provident-total-display">Total: ₱0.00</span>
                        <i class="fas fa-chevron-down coop-chevron" id="provident-chevron"></i>
                    </div>
                </div>
                <div class="coop-block-body" id="provident-body">
                    <div class="coop-subfields" style="grid-template-columns:repeat(4,1fr);">
                        <div class="coop-subfield-item"><label>Provident Fund</label><input type="number" name="provident_fund" class="provident-sub" value="0.00" step="0.01" min="0" oninput="updateTotal('provident')"></div>
                        <div class="coop-subfield-item"><label>Provident Fund Loan</label><input type="number" name="provident_fund_loan" class="provident-sub" value="0.00" step="0.01" min="0" oninput="updateTotal('provident')"></div>
                        <div class="coop-subfield-item"><label>Education Loan</label><input type="number" name="provident_edu_loan" class="provident-sub" value="0.00" step="0.01" min="0" oninput="updateTotal('provident')"></div>
                        <div class="coop-subfield-item"><label>Term Loan</label><input type="number" name="provident_term_loan" class="provident-sub" value="0.00" step="0.01" min="0" oninput="updateTotal('provident')"></div>
                    </div>
                </div>
            </div>

            <!-- PAG-IBIG CONSOLIDATED -->
            <div class="coop-block">
                <div class="coop-block-header" onclick="toggleBlock('pagibig')">
                    <div class="coop-block-title">
                        <span class="coop-tag tag-pagibig">PAG-IBIG CONSOLIDATED</span>
                        <span class="coop-label">Pag-IBIG Fund Loans &amp; Contributions</span>
                    </div>
                    <div class="coop-block-right">
                        <span class="coop-total" id="pagibig-total-display">Total: ₱0.00</span>
                        <i class="fas fa-chevron-down coop-chevron" id="pagibig-chevron"></i>
                    </div>
                </div>
                <div class="coop-block-body" id="pagibig-body">
                    <p class="coop-note"><i class="fas fa-info-circle"></i> Standard Pag-IBIG premium is auto-calculated. Enter amounts here for <strong>additional</strong> loan payments.</p>
                    <div class="coop-subfields" style="grid-template-columns:repeat(5,1fr);">
                        <div class="coop-subfield-item"><label>Multi-Purpose Loan</label><input type="number" name="pagibig_multi" class="pagibig-sub" value="0.00" step="0.01" min="0" oninput="updateTotal('pagibig')"></div>
                        <div class="coop-subfield-item"><label>Emergency Loan</label><input type="number" name="pagibig_emergency" class="pagibig-sub" value="0.00" step="0.01" min="0" oninput="updateTotal('pagibig')"></div>
                        <div class="coop-subfield-item"><label>Premium</label><input type="number" name="pagibig_premium" class="pagibig-sub" value="0.00" step="0.01" min="0" oninput="updateTotal('pagibig')"></div>
                        <div class="coop-subfield-item"><label>MP2</label><input type="number" name="pagibig_mp2" class="pagibig-sub" value="0.00" step="0.01" min="0" oninput="updateTotal('pagibig')"></div>
                        <div class="coop-subfield-item"><label>Housing Loan</label><input type="number" name="pagibig_housing" class="pagibig-sub" value="0.00" step="0.01" min="0" oninput="updateTotal('pagibig')"></div>
                    </div>
                </div>
            </div>

            <!-- GSIS CONSOLIDATED -->
            <div class="coop-block">
                <div class="coop-block-header" onclick="toggleBlock('gsis')">
                    <div class="coop-block-title">
                        <span class="coop-tag tag-gsis">GSIS CONSOLIDATED</span>
                        <span class="coop-label">GSIS Loans &amp; Policy Deductions</span>
                    </div>
                    <div class="coop-block-right">
                        <span class="coop-total" id="gsis-total-display">Total: ₱0.00</span>
                        <i class="fas fa-chevron-down coop-chevron" id="gsis-chevron"></i>
                    </div>
                </div>
                <div class="coop-block-body" id="gsis-body">
                    <p class="coop-note"><i class="fas fa-info-circle"></i> GSIS life/retirement (9%) is auto-calculated. Enter amounts here for <strong>additional</strong> loan payments.</p>
                    <div class="coop-subfields" style="grid-template-columns:repeat(4,1fr);">
                        <div class="coop-subfield-item"><label>GSIS Life &amp; Ret.</label><input type="number" name="gsis_life_ret" class="gsis-sub" value="0.00" step="0.01" min="0" oninput="updateTotal('gsis')"></div>
                        <div class="coop-subfield-item"><label>Emergency Loan</label><input type="number" name="gsis_emergency" class="gsis-sub" value="0.00" step="0.01" min="0" oninput="updateTotal('gsis')"></div>
                        <div class="coop-subfield-item"><label>GSIS-CPL</label><input type="number" name="gsis_cpl" class="gsis-sub" value="0.00" step="0.01" min="0" oninput="updateTotal('gsis')"></div>
                        <div class="coop-subfield-item"><label>GSIS-GPAL</label><input type="number" name="gsis_gpal" class="gsis-sub" value="0.00" step="0.01" min="0" oninput="updateTotal('gsis')"></div>
                        <div class="coop-subfield-item"><label>GSIS-MPL</label><input type="number" name="gsis_mpl" class="gsis-sub" value="0.00" step="0.01" min="0" oninput="updateTotal('gsis')"></div>
                        <div class="coop-subfield-item"><label>GSIS-MPL Lite</label><input type="number" name="gsis_mpl_lite" class="gsis-sub" value="0.00" step="0.01" min="0" oninput="updateTotal('gsis')"></div>
                        <div class="coop-subfield-item"><label>Policy Loan</label><input type="number" name="gsis_policy_loan" class="gsis-sub" value="0.00" step="0.01" min="0" oninput="updateTotal('gsis')"></div>
                    </div>
                </div>
            </div>

            <!-- BACGEM CONSOLIDATED -->
            <div class="coop-block">
                <div class="coop-block-header" onclick="toggleBlock('bacgem')">
                    <div class="coop-block-title">
                        <span class="coop-tag tag-bacgem">BACGEM CONSOLIDATED</span>
                        <span class="coop-label">BAC General Employees Multi-purpose Cooperative</span>
                    </div>
                    <div class="coop-block-right">
                        <span class="coop-total" id="bacgem-total-display">Total: ₱0.00</span>
                        <i class="fas fa-chevron-down coop-chevron" id="bacgem-chevron"></i>
                    </div>
                </div>
                <div class="coop-block-body" id="bacgem-body">
                    <div class="coop-subfields" style="grid-template-columns:repeat(5,1fr);">
                        <div class="coop-subfield-item"><label>Education Loan</label><input type="number" name="bacgem_edu_loan" class="bacgem-sub" value="0.00" step="0.01" min="0" oninput="updateTotal('bacgem')"></div>
                        <div class="coop-subfield-item"><label>Grocery</label><input type="number" name="bacgem_grocery" class="bacgem-sub" value="0.00" step="0.01" min="0" oninput="updateTotal('bacgem')"></div>
                        <div class="coop-subfield-item"><label>Others</label><input type="number" name="bacgem_others" class="bacgem-sub" value="0.00" step="0.01" min="0" oninput="updateTotal('bacgem')"></div>
                        <div class="coop-subfield-item"><label>HCP</label><input type="number" name="bacgem_hcp" class="bacgem-sub" value="0.00" step="0.01" min="0" oninput="updateTotal('bacgem')"></div>
                        <div class="coop-subfield-item"><label>Loan</label><input type="number" name="bacgem_loan" class="bacgem-sub" value="0.00" step="0.01" min="0" oninput="updateTotal('bacgem')"></div>
                    </div>
                </div>
            </div>

            <!-- NOCGEM CONSOLIDATED -->
            <div class="coop-block">
                <div class="coop-block-header" onclick="toggleBlock('nocgem')">
                    <div class="coop-block-title">
                        <span class="coop-tag tag-nocgem">NOCGEM CONSOLIDATED</span>
                        <span class="coop-label">NOC General Employees Multi-purpose Cooperative</span>
                    </div>
                    <div class="coop-block-right">
                        <span class="coop-total" id="nocgem-total-display">Total: ₱0.00</span>
                        <i class="fas fa-chevron-down coop-chevron" id="nocgem-chevron"></i>
                    </div>
                </div>
                <div class="coop-block-body" id="nocgem-body">
                    <div class="coop-subfields" style="grid-template-columns:repeat(4,1fr);">
                        <div class="coop-subfield-item"><label>Education Loan</label><input type="number" name="nocgem_edu_loan" class="nocgem-sub" value="0.00" step="0.01" min="0" oninput="updateTotal('nocgem')"></div>
                        <div class="coop-subfield-item"><label>Emergency</label><input type="number" name="nocgem_emergency" class="nocgem-sub" value="0.00" step="0.01" min="0" oninput="updateTotal('nocgem')"></div>
                        <div class="coop-subfield-item"><label>Grocery</label><input type="number" name="nocgem_grocery" class="nocgem-sub" value="0.00" step="0.01" min="0" oninput="updateTotal('nocgem')"></div>
                        <div class="coop-subfield-item"><label>Hospital</label><input type="number" name="nocgem_hospital" class="nocgem-sub" value="0.00" step="0.01" min="0" oninput="updateTotal('nocgem')"></div>
                        <div class="coop-subfield-item"><label>Others</label><input type="number" name="nocgem_others" class="nocgem-sub" value="0.00" step="0.01" min="0" oninput="updateTotal('nocgem')"></div>
                        <div class="coop-subfield-item"><label>PLP</label><input type="number" name="nocgem_plp" class="nocgem-sub" value="0.00" step="0.01" min="0" oninput="updateTotal('nocgem')"></div>
                        <div class="coop-subfield-item"><label>Regular Loans</label><input type="number" name="nocgem_regular_loan" class="nocgem-sub" value="0.00" step="0.01" min="0" oninput="updateTotal('nocgem')"></div>
                    </div>
                </div>
            </div>

        </div><!-- /deductions-section -->
    </div><!-- /period-config-card -->

    <!-- Department Selection -->
    <div class="departments-selection-card">
        <div class="departments-header">
            <h2><i class="fas fa-building"></i> Select Departments</h2>
            <label class="select-all-button" style="cursor:pointer;">
                <input type="checkbox" id="selectAllDepts" checked onchange="toggleAllDepartments()">
                <span id="selectAllLabel">Deselect All</span>
            </label>
        </div>
        <div class="departments-grid">
            <?php foreach ($departments as $dept): ?>
            <label class="department-checkbox-card selected" id="dept-card-<?php echo $dept['id'];?>">
                <input type="checkbox" name="departments[]" value="<?php echo $dept['id'];?>"
                       class="dept-checkbox" data-dept-id="<?php echo $dept['id'];?>"
                       onchange="updateDepartmentCard(this)" checked>
                <div class="department-info">
                    <h3><?php echo htmlspecialchars($dept['department_name']);?></h3>
                    <div style="display:flex;gap:1rem;align-items:center;margin-top:.5rem;">
                        <span class="department-badge"><?php echo htmlspecialchars($dept['department_code']);?></span>
                        <span class="employee-count"><i class="fas fa-users"></i> <span><?php echo $dept['active_count'];?> active</span></span>
                    </div>
                </div>
            </label>
            <?php endforeach;?>
        </div>
    </div>

    <!-- Generate Summary Bar -->
    <div class="generate-summary">
        <div class="summary-info">
            <h3><i class="fas fa-check-circle"></i> Ready to Generate</h3>
            <p id="summaryText">All departments selected</p>
        </div>
        <button type="submit" class="btn-generate" id="generateBtn">
            <i class="fas fa-bolt"></i> Generate Payroll
        </button>
    </div>

</form>

<?php else: ?>
<div class="card"><div class="card-body" style="text-align:center;padding:4rem 2rem;color:#6b7280;">
    <i class="fas fa-building" style="font-size:4rem;color:#d1d5db;display:block;margin-bottom:1rem;"></i>
    <h3>No Departments Found</h3>
    <p>Please create departments and add employees before generating payroll.</p>
    <a href="departments.php" class="btn btn-primary" style="margin-top:1rem;"><i class="fas fa-plus"></i> Create Department</a>
</div></div>
<?php endif; ?>

<script>
function toggleBlock(prefix) {
    document.getElementById(prefix + '-body').classList.toggle('open');
    document.getElementById(prefix + '-chevron').classList.toggle('open');
}

function updateTotal(prefix) {
    var total = 0;
    document.querySelectorAll('.' + prefix + '-sub').forEach(function(i) { total += parseFloat(i.value) || 0; });
    document.getElementById(prefix + '-total-display').textContent =
        'Total: ₱' + total.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
}

function getPeraLimit() {
    var p = document.getElementById('period_type').value;
    return (p === '1-15' || p.startsWith('16-')) ? 1000 : 2000;
}
function updatePeraField() {
    var limit = getPeraLimit();
    var inp   = document.getElementById('default_pera');
    inp.max   = limit; inp.value = limit.toFixed(2);
    document.getElementById('pera-limit-hint').textContent = 'Max ₱' + limit.toLocaleString('en-PH') + ' for this period';
}

function getDaysInMonth(month, year) {
    var idx = ['January','February','March','April','May','June','July','August','September','October','November','December'].indexOf(month);
    return new Date(year, idx + 1, 0).getDate();
}
function updatePeriodOptions() {
    var month = document.getElementById('payroll_month').value;
    var year  = document.getElementById('payroll_year').value;
    var days  = getDaysInMonth(month, year);
    var sel   = document.getElementById('period_type');
    var cur   = sel.value;
    sel.innerHTML =
        '<option value="1-15">1-15 (First Half)</option>' +
        '<option value="16-' + days + '">16-' + days + ' (Second Half)</option>' +
        '<option value="1-' + days + '">1-' + days + ' (Full Month)</option>';
    if      (cur === '1-15')                         sel.value = '1-15';
    else if (cur.startsWith('16-'))                  sel.value = '16-' + days;
    else if (cur.startsWith('1-') && cur !== '1-15') sel.value = '1-'  + days;
    updatePeraField();
}

function updateDepartmentCard(cb) {
    document.getElementById('dept-card-' + cb.getAttribute('data-dept-id')).classList.toggle('selected', cb.checked);
    updateSummary();
}
function toggleAllDepartments() {
    var sa = document.getElementById('selectAllDepts');
    document.querySelectorAll('.dept-checkbox').forEach(function(cb) {
        cb.checked = sa.checked;
        var card = document.getElementById('dept-card-' + cb.getAttribute('data-dept-id'));
        card.classList.toggle('selected', cb.checked);
    });
    document.getElementById('selectAllLabel').textContent = sa.checked ? 'Deselect All' : 'Select All';
    updateSummary();
}
function updateSummary() {
    var checked = document.querySelectorAll('.dept-checkbox:checked').length;
    var all     = document.querySelectorAll('.dept-checkbox').length;
    var allChecked = (checked === all);
    document.getElementById('selectAllDepts').checked = allChecked;
    document.getElementById('selectAllLabel').textContent = allChecked ? 'Deselect All' : 'Select All';
    document.getElementById('generateBtn').disabled   = (checked === 0);
    document.getElementById('summaryText').textContent = checked + ' department' + (checked !== 1 ? 's' : '') + ' selected';
}

document.getElementById('generateForm').addEventListener('submit', function(e) {
    var checked = document.querySelectorAll('.dept-checkbox:checked').length;
    if (checked === 0) { e.preventDefault(); alert('Please select at least one department.'); return; }
    var limit = getPeraLimit();
    var pera  = parseFloat(document.getElementById('default_pera').value);
    if (pera > limit) { e.preventDefault(); alert('PERA exceeds ₱' + limit.toLocaleString('en-PH') + ' limit for this period.'); return; }
    var month = document.getElementById('payroll_month').value;
    var year  = document.getElementById('payroll_year').value;
    var period= document.getElementById('period_type').value;
    if (!confirm('Generate payroll for ' + checked + ' department(s)?\nPeriod: ' + month + ' ' + period + ', ' + year + '\n\nExisting records will be skipped. Continue?')) { e.preventDefault(); return; }
    var btn = document.getElementById('generateBtn');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
    btn.disabled  = true;
});

document.addEventListener('DOMContentLoaded', function() { updatePeriodOptions(); updateSummary(); });
</script>

<?php require_once 'includes/footer.php'; ?>