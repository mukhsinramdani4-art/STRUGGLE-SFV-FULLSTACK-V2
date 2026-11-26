<?php
/** /api/finance.php — Modul ERP: Keuangan (transaksi income/expense + ringkasan P&L) */
require_once __DIR__ . '/bootstrap.php';
$pdo = db();

switch (method()) {
    case 'GET': {
        require_role(['Super Admin', 'Owner', 'Manager', 'Finance']);
        if (($_GET['view'] ?? '') === 'summary') {
            $months = [];
            for ($i = 5; $i >= 0; $i--) {
                $label = date('Y-m', strtotime("-$i month"));
                $income = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM finance_transactions WHERE type='income' AND strftime('%Y-%m', trx_date) = '$label'")->fetchColumn();
                $expense = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM finance_transactions WHERE type='expense' AND strftime('%Y-%m', trx_date) = '$label'")->fetchColumn();
                $months[] = ['month' => $label, 'income' => $income, 'expense' => $expense, 'profit' => $income - $expense];
            }
            $totalIncome = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM finance_transactions WHERE type='income'")->fetchColumn();
            $totalExpense = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM finance_transactions WHERE type='expense'")->fetchColumn();
            ok(['months' => $months, 'total_income' => $totalIncome, 'total_expense' => $totalExpense, 'net_profit' => $totalIncome - $totalExpense]);
        }
        $rows = $pdo->query("SELECT * FROM finance_transactions ORDER BY trx_date DESC, id DESC LIMIT 300")->fetchAll();
        ok(['transactions' => $rows]);
    }
    case 'POST': {
        require_role(['Super Admin', 'Owner', 'Manager', 'Finance']);
        $b = json_input();
        if (empty($b['type']) || !in_array($b['type'], ['income', 'expense'], true)) fail('type_invalid');
        if (!isset($b['amount'])) fail('amount_required');
        $stmt = $pdo->prepare("INSERT INTO finance_transactions (type, category, amount, description, trx_date, created_by) VALUES (?,?,?,?,?,?)");
        $stmt->execute([
            $b['type'], $b['category'] ?? 'Lainnya', (int)$b['amount'], $b['description'] ?? '',
            $b['date'] ?? date('Y-m-d'), $_SESSION['admin']['email'],
        ]);
        log_activity($_SESSION['admin']['email'], 'create_finance_trx', $b['type'] . ' ' . $b['amount']);
        ok(['id' => $pdo->lastInsertId()]);
    }
    case 'DELETE': {
        require_role(['Super Admin', 'Owner', 'Manager', 'Finance']);
        $id = (int)($_GET['id'] ?? 0);
        $pdo->prepare("DELETE FROM finance_transactions WHERE id = ?")->execute([$id]);
        ok(['deleted' => $id]);
    }
    default: fail('method_not_allowed', 405);
}
