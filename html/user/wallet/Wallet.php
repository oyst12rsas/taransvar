<?php
class Wallet {
    private $conn;
    private $userId;
    private $walletId;
    
    public function __construct($conn, $userId) {
        $this->conn = $conn;
        $this->userId = $userId;
        $this->initWallet();
    }
    
    private function initWallet() {
        // Check if wallet exists for user
        $stmt = $this->conn->prepare("SELECT id FROM wallets WHERE user_id = ?");
        $stmt->execute([$this->userId]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$wallet) {

            $stmt = $this->conn->prepare("INSERT INTO wallets (user_id, created_at) VALUES (?, NOW())");
            $stmt->execute([$this->userId]);
            $this->walletId = $this->conn->lastInsertId();
        } else {
            $this->walletId = $wallet['id'];
        }
    }
    
    public function getBalance() {
        $stmt = $this->conn->prepare("SELECT balance FROM wallets WHERE id = ?");
        $stmt->execute([$this->walletId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ? $result['balance'] : 0;
    }
    
    public function getWalletDetails() {
        $stmt = $this->conn->prepare("
            SELECT w.*, 
                   (SELECT COUNT(*) FROM wallet_transactions WHERE wallet_id = w.id) as total_transactions,
                   (SELECT SUM(amount) FROM wallet_transactions WHERE wallet_id = w.id AND type = 'deposit' AND status = 'completed') as total_deposits,
                   (SELECT SUM(amount) FROM wallet_transactions WHERE wallet_id = w.id AND type = 'withdrawal' AND status = 'completed') as total_withdrawals,
                   (SELECT SUM(amount) FROM wallet_transactions WHERE wallet_id = w.id AND type = 'purchase' AND status = 'completed') as total_purchases
            FROM wallets w
            WHERE w.id = ?
        ");
        $stmt->execute([$this->walletId]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public function getTransactions($limit = null, $offset = 0, $type = null, $status = null) {
        $query = "SELECT * FROM wallet_transactions WHERE wallet_id = ?";
        $params = [$this->walletId];
        
        if ($type) {
            $query .= " AND type = ?";
            $params[] = $type;
        }
        
        if ($status) {
            $query .= " AND status = ?";
            $params[] = $status;
        }
        
        $query .= " ORDER BY created_at DESC";
        
        if ($limit !== null) {
            $query .= " LIMIT ?, ?";
            $params[] = (int)$offset;
            $params[] = (int)$limit;
        }
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function deposit($amount, $paymentMethod, $mpesaCode = null, $description = 'Wallet deposit') {
        if ($amount <= 0) {
            return ['success' => false, 'message' => 'Invalid amount'];
        }
        
        try {
            $this->conn->beginTransaction();
            
            // Add transaction record
            $stmt = $this->conn->prepare("
                INSERT INTO wallet_transactions 
                (wallet_id, user_id, amount, type, status, payment_method, mpesa_code, description, created_at) 
                VALUES (?, ?, ?, 'deposit', 'completed', ?, ?, ?, NOW())
            ");
            $stmt->execute([$this->walletId, $this->userId, $amount, $paymentMethod, $mpesaCode, $description]);
            
            // Update wallet balance
            $stmt = $this->conn->prepare("
                UPDATE wallets 
                SET balance = balance + ?, last_deposit_date = NOW(), updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$amount, $this->walletId]);
            
            $this->conn->commit();
            return ['success' => true, 'message' => 'Deposit successful'];
        } catch (Exception $e) {
            $this->conn->rollBack();
            return ['success' => false, 'message' => 'Deposit failed: ' . $e->getMessage()];
        }
    }
    
    public function withdraw($amount, $description = 'Wallet withdrawal') {
        if ($amount <= 0) {
            return ['success' => false, 'message' => 'Invalid amount'];
        }
        
        $balance = $this->getBalance();
        if ($balance < $amount) {
            return ['success' => false, 'message' => 'Insufficient balance'];
        }
        
        try {
            $this->conn->beginTransaction();
            
            // Add transaction record
            $stmt = $this->conn->prepare("
                INSERT INTO wallet_transactions 
                (wallet_id, user_id, amount, type, status, payment_method, description, created_at) 
                VALUES (?, ?, ?, 'withdrawal', 'completed', 'system', ?, NOW())
            ");
            $stmt->execute([$this->walletId, $this->userId, $amount, $description]);
            
            // Update wallet balance
            $stmt = $this->conn->prepare("
                UPDATE wallets 
                SET balance = balance - ?, last_withdrawal_date = NOW(), updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$amount, $this->walletId]);
            
            $this->conn->commit();
            return ['success' => true, 'message' => 'Withdrawal successful'];
        } catch (Exception $e) {
            $this->conn->rollBack();
            return ['success' => false, 'message' => 'Withdrawal failed: ' . $e->getMessage()];
        }
    }
    
    public function purchase($amount, $referenceId, $description = 'Plan purchase') {
        if ($amount <= 0) {
            return ['success' => false, 'message' => 'Invalid amount'];
        }
        
        $balance = $this->getBalance();
        if ($balance < $amount) {
            return ['success' => false, 'message' => 'Insufficient balance'];
        }
        
        try {
            $this->conn->beginTransaction();
            
            // Add transaction record
            $stmt = $this->conn->prepare("
                INSERT INTO wallet_transactions 
                (wallet_id, user_id, amount, type, status, payment_method, reference_id, description, created_at) 
                VALUES (?, ?, ?, 'purchase', 'completed', 'system', ?, ?, NOW())
            ");
            $stmt->execute([$this->walletId, $this->userId, $amount, $referenceId, $description]);
            
            // Update wallet balance
            $stmt = $this->conn->prepare("
                UPDATE wallets 
                SET balance = balance - ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$amount, $this->walletId]);
            
            $this->conn->commit();
            return ['success' => true, 'message' => 'Purchase successful'];
        } catch (Exception $e) {
            $this->conn->rollBack();
            return ['success' => false, 'message' => 'Purchase failed: ' . $e->getMessage()];
        }
    }
    
    // Method to handle pending M-Pesa deposits
    public function addPendingDeposit($amount, $mpesaCode, $description = 'M-Pesa deposit pending') {
        if ($amount <= 0) {
            return ['success' => false, 'message' => 'Invalid amount'];
        }
        
        try {
            // Add transaction record with pending status
            $stmt = $this->conn->prepare("
                INSERT INTO wallet_transactions 
                (wallet_id, user_id, amount, type, status, payment_method, mpesa_code, description, created_at) 
                VALUES (?, ?, ?, 'deposit', 'pending', 'mpesa', ?, ?, NOW())
            ");
            $stmt->execute([$this->walletId, $this->userId, $amount, $mpesaCode, $description]);
            
            $transactionId = $this->conn->lastInsertId();
            return ['success' => true, 'message' => 'Pending deposit recorded', 'transaction_id' => $transactionId];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Failed to record pending deposit: ' . $e->getMessage()];
        }
    }
    
    // Method to confirm a pending deposit
    public function confirmPendingDeposit($transactionId) {
        try {
            $this->conn->beginTransaction();
            
            // Get transaction details
            $stmt = $this->conn->prepare("
                SELECT * FROM wallet_transactions 
                WHERE id = ? AND wallet_id = ? AND type = 'deposit' AND status = 'pending'
            ");
            $stmt->execute([$transactionId, $this->walletId]);
            $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$transaction) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'Transaction not found or already processed'];
            }
            
            // Update transaction status
            $stmt = $this->conn->prepare("
                UPDATE wallet_transactions 
                SET status = 'completed', updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$transactionId]);
            
            // Update wallet balance
            $stmt = $this->conn->prepare("
                UPDATE wallets 
                SET balance = balance + ?, last_deposit_date = NOW(), updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$transaction['amount'], $this->walletId]);
            
            $this->conn->commit();
            return ['success' => true, 'message' => 'Deposit confirmed successfully'];
        } catch (Exception $e) {
            $this->conn->rollBack();
            return ['success' => false, 'message' => 'Failed to confirm deposit: ' . $e->getMessage()];
        }
    }
    
    // Method to get wallet ID
    public function getWalletId() {
        return $this->walletId;
    }
}
?>