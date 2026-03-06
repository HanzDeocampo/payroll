<?php
/**
 * Payroll System - Consolidated Loans Report
 * Flow: Loan Type → Department → Sub-fields + Employees
 */

require_once 'includes/config.php';
require_once 'includes/auth.php';

$pageTitle = 'Consolidated Loans Report';

// ── Filters ───────────────────────────────────────────────────────────────────
$filterMonth  = isset($_GET['month'])  ? sanitize($_GET['month'])  : '';
$filterYear   = isset($_GET['year'])   ? (int)$_GET['year']        : (int)date('Y');
$filterPeriod = isset($_GET['period']) ? sanitize($_GET['period'])  : '';

// ── Loan type definitions ─────────────────────────────────────────────────────
$loanTypes = [
    'gsis' => [
        'label'     => 'GSIS Loans',
        'color'     => '#14532d',
        'light'     => '#dcfce7',
        'accent'    => '#16a34a',
        'total_col' => 'gsis_total',
        'subfields' => [
            'gsis_life_ret'    => 'GSIS Life & Ret.',
            'gsis_emergency'   => 'Emergency Loan',
            'gsis_cpl'         => 'GSIS-CPL',
            'gsis_gpal'        => 'GSIS-GPAL',
            'gsis_mpl'         => 'GSIS-MPL',
            'gsis_mpl_lite'    => 'GSIS-MPL Lite',
            'gsis_policy_loan' => 'Policy Loan',
        ],
    ],
    'pagibig' => [
        'label'     => 'Pag-IBIG Loans',
        'color'     => '#9d174d',
        'light'     => '#fce7f3',
        'accent'    => '#db2777',
        'total_col' => 'pagibig_total',
        'subfields' => [
            'pagibig_multi'     => 'Multi-Purpose Loan',
            'pagibig_emergency' => 'Emergency Loan',
            'pagibig_premium'   => 'Premium',
            'pagibig_mp2'       => 'MP2',
            'pagibig_housing'   => 'Housing Loan',
        ],
    ],
    'provident' => [
        'label'     => 'Provident Fund',
        'color'     => '#5b21b6',
        'light'     => '#ede9fe',
        'accent'    => '#7c3aed',
        'total_col' => 'provident_total',
        'subfields' => [
            'provident_fund'      => 'Fund Contribution',
            'provident_fund_loan' => 'Fund Loan',
            'provident_edu_loan'  => 'Education Loan',
            'provident_term_loan' => 'Term Loan',
        ],
    ],
    'bcgeu' => [
        'label'     => 'BCGEU',
        'color'     => '#3730a3',
        'light'     => '#e0e7ff',
        'accent'    => '#4f46e5',
        'total_col' => 'bcgeu_total',
        'subfields' => [
            'bcgeu_edu_loan'     => 'Education Loan',
            'bcgeu_emergency'    => 'Emergency Loan',
            'bcgeu_grocery'      => 'Grocery Loan',
            'bcgeu_regular_loan' => 'Regular Loan',
            'bcgeu_others'       => 'Others',
        ],
    ],
    'bacgem' => [
        'label'     => 'BACGEM',
        'color'     => '#1e3a8a',
        'light'     => '#dbeafe',
        'accent'    => '#2563eb',
        'total_col' => 'bacgem_total',
        'subfields' => [
            'bacgem_edu_loan' => 'Education Loan',
            'bacgem_grocery'  => 'Grocery',
            'bacgem_others'   => 'Others',
            'bacgem_hcp'      => 'HCP',
            'bacgem_loan'     => 'Loan',
        ],
    ],
    'nocgem' => [
        'label'     => 'NOCGEM',
        'color'     => '#064e3b',
        'light'     => '#d1fae5',
        'accent'    => '#059669',
        'total_col' => 'nocgem_total',
        'subfields' => [
            'nocgem_edu_loan'     => 'Education Loan',
            'nocgem_emergency'    => 'Emergency Loan',
            'nocgem_grocery'      => 'Grocery',
            'nocgem_hospital'     => 'Hospital',
            'nocgem_others'       => 'Others',
            'nocgem_plp'          => 'PLP',
            'nocgem_regular_loan' => 'Regular Loan',
        ],
    ],
    'other' => [
        'label'     => 'Other Deductions',
        'color'     => '#92400e',
        'light'     => '#fef3c7',
        'accent'    => '#d97706',
        'total_col' => 'other_total',
        'subfields' => [
            'other_cash_advance' => 'Cash Advance',
            'other_insurance'    => 'Insurance',
            'other_salary_loan'  => 'Salary Loan',
            'other_others'       => 'Others',
        ],
    ],
];

// ── Build filter SQL ──────────────────────────────────────────────────────────
$filterParts = ['1=1'];
if ($filterMonth)  $filterParts[] = "c.payroll_month = '" . $conn->real_escape_string($filterMonth) . "'";
if ($filterYear)   $filterParts[] = "c.payroll_year = " . (int)$filterYear;
if ($filterPeriod) $filterParts[] = "c.period_type = '" . $conn->real_escape_string($filterPeriod) . "'";
$filterSQL = implode(' AND ', $filterParts);

// ── Grand summary totals ──────────────────────────────────────────────────────
$summaryRow = $conn->query("
    SELECT
        SUM(gsis_total)      AS gsis,
        SUM(pagibig_total)   AS pagibig,
        SUM(provident_total) AS provident,
        SUM(bcgeu_total)     AS bcgeu,
        SUM(bacgem_total)    AS bacgem,
        SUM(nocgem_total)    AS nocgem,
        SUM(other_total)     AS other,
        SUM(grand_total)     AS grand
    FROM consolidated c WHERE $filterSQL
")->fetch_assoc();

// ── Available periods ─────────────────────────────────────────────────────────
$availPeriods = $conn->query("SELECT DISTINCT period_type FROM consolidated ORDER BY period_type");

// ── Helpers ───────────────────────────────────────────────────────────────────
function fmt($n)  { return '₱' . number_format((float)$n, 2); }
function fmtN($n) { return (float)$n > 0 ? '₱' . number_format((float)$n, 2) : '<span class="zero">—</span>'; }

require_once 'includes/header.php';
?>

<style>
.cl-page { font-family: 'Segoe UI', sans-serif; }

/* ── Top bar ── */
.cl-topbar {
    background: linear-gradient(135deg, #0f2640 0%, #1a3d6b 60%, #133254 100%);
    border-radius: 18px; padding: 1.75rem 2rem; margin-bottom: 1.5rem;
    color: #fff; position: relative; overflow: hidden;
}
.cl-topbar::after {
    content: ''; position: absolute; top: -40px; right: -40px;
    width: 220px; height: 220px;
    background: radial-gradient(circle, rgba(201,162,39,.18) 0%, transparent 70%);
    border-radius: 50%; pointer-events: none;
}
.cl-topbar-inner { position: relative; z-index: 1; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; }
.cl-topbar h1 { font-size: 1.6rem; font-weight: 800; margin: 0 0 4px; letter-spacing: -0.3px; }
.cl-topbar p  { margin: 0; color: #7eb3e0; font-size: 0.9rem; }
.cl-breadcrumb { display: flex; align-items: center; gap: 6px; margin-bottom: 6px; font-size: 0.8rem; }
.cl-breadcrumb a { color: #7eb3e0; text-decoration: none; }
.cl-breadcrumb a:hover { color: #fff; }
.cl-breadcrumb span { color: #3d6a96; }
.cl-back-btn {
    background: rgba(255,255,255,.12); color: #fff; padding: 9px 18px; border-radius: 9px;
    text-decoration: none; font-weight: 600; font-size: 0.875rem;
    border: 1px solid rgba(255,255,255,.2); display: inline-block; transition: background .15s;
}
.cl-back-btn:hover { background: rgba(255,255,255,.2); color: #fff; }

/* ── Filters ── */
.cl-filters {
    background: #fff; border-radius: 14px; padding: 1.25rem 1.5rem;
    margin-bottom: 1.5rem; box-shadow: 0 2px 12px rgba(0,0,0,0.07);
    display: flex; align-items: flex-end; gap: 1rem; flex-wrap: wrap;
}
.cl-filter-group { display: flex; flex-direction: column; gap: 4px; }
.cl-filter-group label { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #6b7280; }
.cl-filter-group select {
    padding: 9px 14px; border: 2px solid #e5e7eb; border-radius: 9px;
    font-size: 0.875rem; font-weight: 500; color: #111827; min-width: 150px;
    background: #f9fafb; transition: border-color .15s; font-family: inherit;
}
.cl-filter-group select:focus { outline: none; border-color: #2d6394; background: #fff; }
.cl-filter-btn {
    padding: 10px 22px; background: #1a3d6b; color: #fff; border: none; border-radius: 9px;
    font-weight: 700; font-size: 0.875rem; cursor: pointer; transition: all .15s;
    align-self: flex-end; font-family: inherit;
}
.cl-filter-btn:hover { background: #2d6394; transform: translateY(-1px); }
.cl-reset-btn {
    padding: 10px 16px; background: #f3f4f6; color: #6b7280; border: 2px solid #e5e7eb;
    border-radius: 9px; font-weight: 600; font-size: 0.875rem; font-family: inherit;
    text-decoration: none; display: inline-block; align-self: flex-end; transition: all .15s;
}
.cl-reset-btn:hover { border-color: #9ca3af; color: #374151; }

/* ── Summary cards ── */
.cl-summary-row {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(148px, 1fr));
    gap: 1rem; margin-bottom: 1.75rem;
}
.cl-sum-card {
    background: #fff; border-radius: 12px; padding: 1rem 1.25rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.07); border-left: 4px solid transparent;
    cursor: pointer; transition: box-shadow .15s, transform .15s;
}
.cl-sum-card:hover { box-shadow: 0 6px 20px rgba(0,0,0,0.12); transform: translateY(-2px); }
.cl-sum-card-label { font-size: 0.66rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: #9ca3af; margin-bottom: 6px; }
.cl-sum-card-value { font-size: 1.05rem; font-weight: 800; color: #111827; }
.cl-sum-card.cs-gsis     { border-color: #16a34a; }
.cl-sum-card.cs-pagibig  { border-color: #db2777; }
.cl-sum-card.cs-provid   { border-color: #7c3aed; }
.cl-sum-card.cs-bcgeu    { border-color: #4f46e5; }
.cl-sum-card.cs-bacgem   { border-color: #2563eb; }
.cl-sum-card.cs-nocgem   { border-color: #059669; }
.cl-sum-card.cs-other    { border-color: #d97706; }
.cl-sum-card.cs-grand    { border-color: #1a3d6b; background: #f0f6ff; }
.cl-sum-card.cs-grand .cl-sum-card-value { color: #1a3d6b; font-size: 1.2rem; }

/* ── LEVEL 1: Loan type block ── */
.loan-block {
    margin-bottom: 1.25rem;
    border-radius: 14px; overflow: hidden;
    box-shadow: 0 3px 14px rgba(0,0,0,0.08);
    border: 1px solid #e5e7eb;
}

.loan-header {
    padding: 1.1rem 1.5rem;
    display: flex; align-items: center; justify-content: space-between;
    cursor: pointer; user-select: none; transition: filter .15s;
}
.loan-header:hover { filter: brightness(.97); }
.loan-header-left { display: flex; align-items: center; gap: 14px; }

.loan-label-tag {
    padding: 5px 14px; border-radius: 7px;
    font-size: 0.78rem; font-weight: 800; letter-spacing: .05em;
}
.loan-title-wrap {}
.loan-title { font-size: 1.05rem; font-weight: 700; }
.loan-meta  { font-size: 0.78rem; margin-top: 2px; opacity: .65; }

.loan-header-right { display: flex; align-items: center; gap: 12px; }
.loan-grand-pill {
    padding: 5px 16px; border-radius: 20px;
    font-size: 0.875rem; font-weight: 800;
    background: rgba(0,0,0,.07);
}
.loan-arrow {
    width: 26px; height: 26px; border-radius: 50%;
    background: rgba(0,0,0,.08);
    display: flex; align-items: center; justify-content: center;
    font-size: 0.75rem; font-weight: 900; transition: transform .25s;
}
.loan-arrow.open { transform: rotate(180deg); }

.loan-body { display: none; }
.loan-body.open { display: block; }

/* ── LEVEL 2: Department block ── */
.dept-block {
    border-top: 1px solid #f0f0f0;
}
.dept-block:first-child { border-top: none; }

.dept-header {
    padding: .85rem 1.75rem;
    display: flex; align-items: center; justify-content: space-between;
    cursor: pointer; background: #fafafa; user-select: none; transition: background .12s;
}
.dept-header:hover { background: #f3f4f6; }
.dept-header-left { display: flex; align-items: center; gap: 10px; }

.dept-code-pill {
    padding: 3px 10px; border-radius: 6px; font-size: 0.72rem; font-weight: 800;
    background: #e0e7ff; color: #3730a3; letter-spacing: .03em;
}
.dept-name  { font-weight: 700; color: #111827; font-size: 0.9rem; }
.dept-count { font-size: 0.75rem; color: #9ca3af; }

.dept-header-right { display: flex; align-items: center; gap: 10px; }
.dept-total-pill {
    padding: 3px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: 700;
}
.dept-arrow {
    width: 20px; height: 20px; border-radius: 50%; background: #e5e7eb;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.65rem; font-weight: 900; color: #6b7280;
    transition: transform .2s;
}
.dept-arrow.open { transform: rotate(180deg); }

.dept-body { display: none; background: #fff; }
.dept-body.open { display: block; animation: fadeIn .18s ease; }

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-3px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* ── Sub-field list inside dept body ── */
.subfield-list {
    padding: 1rem 1.75rem .5rem;
    display: flex; flex-direction: column; gap: 0;
    border-bottom: 2px solid #f0f0f0;
}
.subfield-list-title {
    font-size: 0.68rem; font-weight: 800; text-transform: uppercase;
    letter-spacing: .08em; color: #9ca3af; margin-bottom: .6rem;
}
.subfield-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: .45rem 0;
    border-bottom: 1px dashed #f0f0f0;
    font-size: 0.85rem;
}
.subfield-row:last-child { border-bottom: none; }
.subfield-row-name  { color: #374151; font-weight: 500; }
.subfield-row-total { font-weight: 700; font-size: 0.82rem; }

/* ── Employee table ── */
.emp-section {
    padding: .75rem 1.75rem 1.25rem;
}
.emp-section-title {
    font-size: 0.68rem; font-weight: 800; text-transform: uppercase;
    letter-spacing: .08em; color: #9ca3af; margin-bottom: .75rem;
}

.emp-table {
    width: 100%; border-collapse: collapse; font-size: 0.82rem;
}
.emp-table thead tr { background: #f8fafc; }
.emp-table th {
    padding: 8px 12px; font-weight: 700; color: #374151;
    border-bottom: 2px solid #e5e7eb; white-space: nowrap;
    font-size: 0.72rem; text-transform: uppercase; letter-spacing: .04em;
    text-align: left;
}
.emp-table th.num { text-align: right; }
.emp-table td {
    padding: 9px 12px; border-bottom: 1px solid #f3f4f6;
    color: #374151; vertical-align: middle; text-align: left;
}
.emp-table td.num { text-align: right; }
.emp-table tbody tr:last-child td { border-bottom: none; }
.emp-table tbody tr:hover td { background: #fafeff; }

.emp-avatar {
    width: 30px; height: 30px; border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 0.72rem; flex-shrink: 0;
    vertical-align: middle; margin-right: 8px;
}
.emp-name-cell { display: inline-flex; align-items: center; }
.emp-full-name { font-weight: 700; color: #111827; }
.emp-id-code   { font-size: 0.72rem; color: #9ca3af; font-family: monospace; margin-left: 6px; }

.period-tag {
    display: inline-block; padding: 2px 8px; border-radius: 5px;
    background: #eff6ff; color: #1d4ed8; font-size: 0.7rem;
    font-weight: 600; white-space: nowrap;
}

/* subtotal / total rows */
.emp-subtotal-row td { background: #f8fafc !important; font-weight: 700 !important; font-size: 0.78rem; }
.dept-tfoot-row td {
    background: #f0f6ff !important; font-weight: 800 !important;
    color: #1a3d6b !important; border-top: 2px solid #dbeafe !important;
    border-bottom: none !important; font-size: 0.8rem;
}

.zero { color: #d1d5db; }

/* ── Empty ── */
.cl-empty {
    text-align: center; padding: 4rem 2rem; background: #fff;
    border-radius: 16px; box-shadow: 0 2px 8px rgba(0,0,0,.06); color: #9ca3af;
}
.cl-empty h3 { font-size: 1.05rem; font-weight: 700; color: #6b7280; margin-bottom: 6px; }

@media (max-width: 768px) {
    .emp-table { display: block; overflow-x: auto; }
    .cl-summary-row { grid-template-columns: repeat(2, 1fr); }
    .subfield-list, .emp-section { padding-left: 1rem; padding-right: 1rem; }
}
</style>

<div class="cl-page">

<!-- Top bar -->
<div class="cl-topbar">
    <div class="cl-topbar-inner">
        <div>
            <div class="cl-breadcrumb">
                <a href="index.php">Home</a>
                <span>/</span>
                <span style="color:#7eb3e0;">Consolidated Loans Report</span>
            </div>
            <h1>Consolidated Loans Report</h1>
            <p>Browse loan deductions by type, then drill down by department and employee</p>
        </div>
        <a href="payroll.php" class="cl-back-btn">Back to Payroll</a>
    </div>
</div>

<!-- Filters -->
<form method="GET" class="cl-filters">
    <div class="cl-filter-group">
        <label>Month</label>
        <select name="month">
            <option value="">All Months</option>
            <?php foreach (['January','February','March','April','May','June','July','August','September','October','November','December'] as $mo): ?>
            <option value="<?php echo $mo; ?>" <?php echo $filterMonth === $mo ? 'selected' : ''; ?>><?php echo $mo; ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="cl-filter-group">
        <label>Year</label>
        <select name="year">
            <?php for ($y = date('Y') + 1; $y >= date('Y') - 3; $y--): ?>
            <option value="<?php echo $y; ?>" <?php echo $filterYear == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
            <?php endfor; ?>
        </select>
    </div>
    <div class="cl-filter-group">
        <label>Period</label>
        <select name="period">
            <option value="">All Periods</option>
            <?php while ($p = $availPeriods->fetch_assoc()): ?>
            <option value="<?php echo $p['period_type']; ?>" <?php echo $filterPeriod === $p['period_type'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($p['period_type']); ?>
            </option>
            <?php endwhile; ?>
        </select>
    </div>
    <button type="submit" class="cl-filter-btn">Apply</button>
    <a href="consolidated_loans.php" class="cl-reset-btn">Reset</a>
</form>

<!-- Grand summary cards -->
<div class="cl-summary-row">
    <?php
    $cardMap = [
        'gsis'      => ['GSIS Loans',      'cs-gsis',    'gsis'],
        'pagibig'   => ['Pag-IBIG Loans',  'cs-pagibig', 'pagibig'],
        'provident' => ['Provident',        'cs-provid',  'provident'],
        'bcgeu'     => ['BCGEU',            'cs-bcgeu',   'bcgeu'],
        'bacgem'    => ['BACGEM',           'cs-bacgem',  'bacgem'],
        'nocgem'    => ['NOCGEM',           'cs-nocgem',  'nocgem'],
        'other'     => ['Other Deductions', 'cs-other',   'other'],
        'grand'     => ['Grand Total',      'cs-grand',   'grand'],
    ];
    foreach ($cardMap as $key => [$label, $cls, $dataKey]):
    ?>
    <div class="cl-sum-card <?php echo $cls; ?>"
         <?php if ($key !== 'grand'): ?>onclick="scrollToLoan('<?php echo $key; ?>')"<?php endif; ?>>
        <div class="cl-sum-card-label"><?php echo $label; ?></div>
        <div class="cl-sum-card-value"><?php echo fmt($summaryRow[$dataKey] ?? 0); ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ═══════════════════════════════════════════════════════════
     LEVEL 1: Loan type blocks
     ═══════════════════════════════════════════════════════════ -->
<?php foreach ($loanTypes as $loanKey => $loan):

    // Grand total for this loan type
    $loanTotal = (float)$conn->query("
        SELECT SUM({$loan['total_col']}) FROM consolidated c WHERE $filterSQL AND {$loan['total_col']} > 0
    ")->fetch_row()[0];

    // Departments that have this loan type
    $deptRows = $conn->query("
        SELECT d.id, d.department_name, d.department_code,
               SUM(c.{$loan['total_col']}) AS dept_total,
               COUNT(DISTINCT c.employee_id) AS emp_count
        FROM consolidated c
        JOIN departments d ON c.department_id = d.id
        WHERE $filterSQL AND c.{$loan['total_col']} > 0
        GROUP BY d.id, d.department_name, d.department_code
        ORDER BY d.department_name ASC
    ");
    $depts = [];
    while ($d = $deptRows->fetch_assoc()) $depts[] = $d;

    $totalDepts = count($depts);
    $totalEmps  = array_sum(array_column($depts, 'emp_count'));

    $lId = 'loan_' . $loanKey;
?>
<div class="loan-block" id="anchor-<?php echo $loanKey; ?>">

    <!-- Loan type header -->
    <div class="loan-header"
         style="background:<?php echo $loan['light']; ?>;"
         onclick="toggleLoan('<?php echo $lId; ?>')">
        <div class="loan-header-left">
            <span class="loan-label-tag"
                  style="background:<?php echo $loan['accent']; ?>1a;color:<?php echo $loan['color']; ?>;border:1.5px solid <?php echo $loan['accent']; ?>40;">
                <?php echo strtoupper($loanKey); ?>
            </span>
            <div class="loan-title-wrap">
                <div class="loan-title" style="color:<?php echo $loan['color']; ?>;">
                    <?php echo htmlspecialchars($loan['label']); ?>
                </div>
                <div class="loan-meta" style="color:<?php echo $loan['color']; ?>;">
                    <?php echo $totalDepts; ?> department<?php echo $totalDepts != 1 ? 's' : ''; ?>
                    &nbsp;·&nbsp;
                    <?php echo $totalEmps; ?> employee<?php echo $totalEmps != 1 ? 's' : ''; ?>
                </div>
            </div>
        </div>
        <div class="loan-header-right">
            <span class="loan-grand-pill" style="color:<?php echo $loan['color']; ?>;">
                <?php echo fmt($loanTotal); ?>
            </span>
            <span class="loan-arrow" style="color:<?php echo $loan['color']; ?>;"
                  id="larrow-<?php echo $lId; ?>">▾</span>
        </div>
    </div>

    <!-- Loan body -->
    <div class="loan-body" id="lbody-<?php echo $lId; ?>">

        <?php if (empty($depts)): ?>
        <div style="padding:1.5rem 1.75rem;color:#9ca3af;font-size:0.875rem;">
            No records found for this loan type with the current filters.
        </div>
        <?php else: ?>

        <!-- ═══════════════════════════════════════════════════
             LEVEL 2: Department blocks
             ═══════════════════════════════════════════════════ -->
        <?php foreach ($depts as $dept):
            $dId = $lId . '_dept_' . $dept['id'];

            // ── Sub-field totals for this dept ────────────────
            $sfCols   = implode(', ', array_map(fn($f) => "SUM(c.$f) AS $f", array_keys($loan['subfields'])));
            $sfTotals = $conn->query("
                SELECT $sfCols
                FROM consolidated c
                WHERE $filterSQL AND c.department_id = {$dept['id']} AND c.{$loan['total_col']} > 0
            ")->fetch_assoc();

            // ── Employee records for this dept ────────────────
            $sfSelect = implode(', ', array_map(fn($f) => "c.$f", array_keys($loan['subfields'])));
            $empResult = $conn->query("
                SELECT
                    c.id, c.payroll_month, c.payroll_year, c.period_type,
                    c.{$loan['total_col']} AS loan_total,
                    $sfSelect,
                    e.id AS eid,
                    e.employee_id AS emp_code,
                    e.first_name, e.last_name, e.middle_name
                FROM consolidated c
                JOIN employees e ON c.employee_id = e.id
                WHERE $filterSQL
                  AND c.department_id = {$dept['id']}
                  AND c.{$loan['total_col']} > 0
                ORDER BY e.last_name ASC, e.first_name ASC,
                         c.payroll_year ASC,
                         FIELD(c.payroll_month,'January','February','March','April','May',
                               'June','July','August','September','October','November','December') ASC,
                         c.period_type ASC
            ");

            // Group by employee
            $empGroups = [];
            while ($er = $empResult->fetch_assoc()) {
                $eid = $er['eid'];
                if (!isset($empGroups[$eid])) {
                    $mi = $er['middle_name'] ? ' ' . substr($er['middle_name'], 0, 1) . '.' : '';
                    $initials = strtoupper(substr($er['first_name'], 0, 1) . substr($er['last_name'], 0, 1));
                    $empGroups[$eid] = [
                        'name'     => $er['last_name'] . ', ' . $er['first_name'] . $mi,
                        'code'     => $er['emp_code'],
                        'initials' => $initials,
                        'records'  => [],
                        'total'    => 0,
                    ];
                }
                $empGroups[$eid]['records'][] = $er;
                $empGroups[$eid]['total']    += (float)$er['loan_total'];
            }
        ?>
        <div class="dept-block">

            <!-- Department header -->
            <div class="dept-header" onclick="toggleDept('<?php echo $dId; ?>')">
                <div class="dept-header-left">
                    <span class="dept-code-pill"><?php echo htmlspecialchars($dept['department_code']); ?></span>
                    <span class="dept-name"><?php echo htmlspecialchars($dept['department_name']); ?></span>
                    <span class="dept-count">
                        <?php echo $dept['emp_count']; ?> employee<?php echo $dept['emp_count'] != 1 ? 's' : ''; ?>
                    </span>
                </div>
                <div class="dept-header-right">
                    <span class="dept-total-pill"
                          style="background:<?php echo $loan['light']; ?>;color:<?php echo $loan['color']; ?>;">
                        <?php echo fmt($dept['dept_total']); ?>
                    </span>
                    <span class="dept-arrow" id="darrow-<?php echo $dId; ?>">▾</span>
                </div>
            </div>

            <!-- Department body -->
            <div class="dept-body" id="dbody-<?php echo $dId; ?>">

                <!-- ── Sub-field breakdown list ── -->
                <div class="subfield-list">
                    <div class="subfield-list-title" style="color:<?php echo $loan['accent']; ?>;">
                        <?php echo htmlspecialchars($loan['label']); ?> — Breakdown
                    </div>
                    <?php foreach ($loan['subfields'] as $sf => $sfLabel):
                        $sfVal = (float)($sfTotals[$sf] ?? 0);
                    ?>
                    <div class="subfield-row">
                        <span class="subfield-row-name"><?php echo htmlspecialchars($sfLabel); ?></span>
                        <span class="subfield-row-total" style="color:<?php echo $sfVal > 0 ? $loan['color'] : '#d1d5db'; ?>;">
                            <?php echo $sfVal > 0 ? fmt($sfVal) : '—'; ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                    <!-- Sub-field total -->
                    <div class="subfield-row" style="border-top:2px solid <?php echo $loan['accent']; ?>20;margin-top:4px;padding-top:8px;">
                        <span class="subfield-row-name" style="font-weight:800;color:<?php echo $loan['color']; ?>;">Total</span>
                        <span class="subfield-row-total" style="font-size:.9rem;color:<?php echo $loan['color']; ?>;">
                            <?php echo fmt($dept['dept_total']); ?>
                        </span>
                    </div>
                </div>

                <!-- ── Employee table ── -->
                <div class="emp-section">
                    <div class="emp-section-title" style="color:<?php echo $loan['accent']; ?>;">
                        Employees who availed — <?php echo htmlspecialchars($dept['department_name']); ?>
                    </div>
                    <table class="emp-table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Period</th>
                                <?php foreach ($loan['subfields'] as $sf => $sfLabel): ?>
                                <th class="num"><?php echo htmlspecialchars($sfLabel); ?></th>
                                <?php endforeach; ?>
                                <th class="num">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($empGroups as $empData):
                            $recCount = count($empData['records']);
                        ?>
                            <?php foreach ($empData['records'] as $rIdx => $rec): ?>
                            <tr>
                                <?php if ($rIdx === 0): ?>
                                <td rowspan="<?php echo $recCount; ?>" style="vertical-align:top;padding-top:10px;">
                                    <div class="emp-name-cell">
                                        <span class="emp-avatar"
                                              style="background:<?php echo $loan['light']; ?>;color:<?php echo $loan['color']; ?>;">
                                            <?php echo htmlspecialchars($empData['initials']); ?>
                                        </span>
                                        <div>
                                            <div class="emp-full-name"><?php echo htmlspecialchars($empData['name']); ?></div>
                                            <div class="emp-id-code"><?php echo htmlspecialchars($empData['code']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <?php endif; ?>
                                <td>
                                    <span class="period-tag">
                                        <?php echo htmlspecialchars($rec['payroll_month'] . ' ' . $rec['period_type'] . ', ' . $rec['payroll_year']); ?>
                                    </span>
                                </td>
                                <?php foreach ($loan['subfields'] as $sf => $sfLabel): ?>
                                <td class="num"><?php echo fmtN($rec[$sf] ?? 0); ?></td>
                                <?php endforeach; ?>
                                <td class="num">
                                    <strong style="color:<?php echo $loan['color']; ?>;">
                                        <?php echo fmt($rec['loan_total']); ?>
                                    </strong>
                                </td>
                            </tr>
                            <?php endforeach; ?>

                            <?php if ($recCount > 1): ?>
                            <tr class="emp-subtotal-row">
                                <td colspan="<?php echo 1 + count($loan['subfields']); ?>"
                                    style="text-align:right;color:<?php echo $loan['color']; ?>;letter-spacing:.02em;">
                                    <?php echo htmlspecialchars($empData['name']); ?> total
                                </td>
                                <td class="num" style="color:<?php echo $loan['color']; ?>;">
                                    <?php echo fmt($empData['total']); ?>
                                </td>
                            </tr>
                            <?php endif; ?>

                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="dept-tfoot-row">
                                <td colspan="<?php echo 1 + count($loan['subfields']); ?>"
                                    style="text-align:right;text-transform:uppercase;letter-spacing:.04em;">
                                    <?php echo htmlspecialchars($dept['department_code']); ?> Department Total
                                </td>
                                <td class="num"><?php echo fmt($dept['dept_total']); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

            </div><!-- /.dept-body -->
        </div><!-- /.dept-block -->
        <?php endforeach; ?>
        <?php endif; ?>

    </div><!-- /.loan-body -->
</div><!-- /.loan-block -->
<?php endforeach; ?>

</div><!-- /.cl-page -->

<script>
function toggleLoan(id) {
    const body  = document.getElementById('lbody-'  + id);
    const arrow = document.getElementById('larrow-' + id);
    const open  = body.classList.toggle('open');
    arrow.classList.toggle('open', open);
}

function toggleDept(id) {
    const body  = document.getElementById('dbody-'  + id);
    const arrow = document.getElementById('darrow-' + id);
    const open  = body.classList.toggle('open');
    arrow.classList.toggle('open', open);
}

function scrollToLoan(key) {
    const el = document.getElementById('anchor-' + key);
    if (!el) return;
    el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    // Auto-open if closed
    const lId   = 'loan_' + key;
    const body  = document.getElementById('lbody-'  + lId);
    const arrow = document.getElementById('larrow-' + lId);
    if (body && !body.classList.contains('open')) {
        body.classList.add('open');
        if (arrow) arrow.classList.add('open');
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>