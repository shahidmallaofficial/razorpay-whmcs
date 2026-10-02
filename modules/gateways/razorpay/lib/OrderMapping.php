<?php
/**
 * Razorpay Payment Gateway for WHMCS v3.0.0
 * Developed by Shahid Malla - https://shahidmalla.com
 * MIT License
 */

namespace RazorpayWhmcs;

use WHMCS\Database\Capsule;

class OrderMapping
{
    const TABLE          = 'tblrzpordermapping';
    const SCHEMA_VERSION = '4';

    const STATUS_CREATED = 'created';
    const STATUS_PAID    = 'paid';
    const STATUS_REVIEW  = 'review';
    const STATUS_INVALID = 'invalid';

    private static $schemaChecked = false;

    public static function ensureSchema($force = false)
    {
        if (self::$schemaChecked && !$force) {
            return;
        }

        if (!$force && self::storedSchemaVersion() === self::SCHEMA_VERSION) {
            self::$schemaChecked = true;
            return;
        }

        $lock = Gateway::lock('rzp_whmcs_schema', 30);

        try {
            if (!$force && self::storedSchemaVersion() === self::SCHEMA_VERSION) {
                self::$schemaChecked = true;
                return;
            }

            $schema = Capsule::schema();

            if (!$schema->hasTable(self::TABLE)) {
                try {
                    $schema->create(self::TABLE, function ($table) {
                        $table->increments('id');
                        $table->string('merchant_order_id', 20)->index();
                        $table->string('razorpay_order_id', 40)->index();
                    });
                } catch (\Exception $e) {
                    if (!$schema->hasTable(self::TABLE)) {
                        throw $e;
                    }
                }
            }

            self::upgradeTable($schema);
            self::storeSchemaVersion();
            self::$schemaChecked = true;
        } finally {
            Gateway::unlock($lock);
        }
    }

    private static function upgradeTable($schema)
    {
        $columns = array(
            'amount'              => function ($table) { $table->bigInteger('amount')->nullable(); },
            'currency'            => function ($table) { $table->string('currency', 3)->nullable(); },
            'invoice_amount'      => function ($table) { $table->decimal('invoice_amount', 16, 2)->nullable(); },
            'status'              => function ($table) { $table->string('status', 16)->default(self::STATUS_CREATED); },
            'razorpay_payment_id' => function ($table) { $table->string('razorpay_payment_id', 40)->nullable(); },
            'key_id'              => function ($table) { $table->string('key_id', 64)->nullable(); },
            'capture_mode'        => function ($table) { $table->string('capture_mode', 16)->nullable(); },
            'created_at'          => function ($table) { $table->dateTime('created_at')->nullable(); },
            'updated_at'          => function ($table) { $table->dateTime('updated_at')->nullable(); },
            'checked_at'          => function ($table) { $table->dateTime('checked_at')->nullable(); },
        );

        foreach ($columns as $name => $definition) {
            if ($schema->hasColumn(self::TABLE, $name)) {
                continue;
            }

            try {
                $schema->table(self::TABLE, $definition);
            } catch (\Exception $e) {
                if (!$schema->hasColumn(self::TABLE, $name)) {
                    throw $e;
                }
            }
        }

        if (Capsule::connection()->getDriverName() !== 'mysql') {
            return;
        }

        try {
            $column = Capsule::selectOne(
                'SELECT CHARACTER_MAXIMUM_LENGTH AS len FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                array(self::TABLE, 'razorpay_order_id')
            );
            if ($column && (int) $column->len < 40) {
                Capsule::statement('ALTER TABLE `' . self::TABLE . '` MODIFY `razorpay_order_id` VARCHAR(40) NOT NULL');
            }
        } catch (\Exception $e) {
            Logger::log('Schema upgrade: widen razorpay_order_id', $e->getMessage(), 'Notice');
        }

        foreach (array('merchant_order_id', 'razorpay_order_id') as $column) {
            $index = self::TABLE . '_' . $column . '_index';
            try {
                $exists = Capsule::select('SHOW INDEX FROM `' . self::TABLE . '` WHERE Column_name = ? AND Seq_in_index = 1', array($column));
                if (empty($exists)) {
                    Capsule::statement('ALTER TABLE `' . self::TABLE . '` ADD INDEX `' . $index . '` (`' . $column . '`)');
                }
            } catch (\Exception $e) {
                Logger::log('Schema upgrade: add index ' . $column, $e->getMessage(), 'Notice');
            }
        }
    }

    private static function storedSchemaVersion()
    {
        try {
            if (class_exists('\WHMCS\Config\Setting')) {
                return (string) \WHMCS\Config\Setting::getValue('RazorpayMappingSchema');
            }
        } catch (\Exception $e) {
        }

        return '';
    }

    private static function storeSchemaVersion()
    {
        try {
            if (class_exists('\WHMCS\Config\Setting')) {
                \WHMCS\Config\Setting::setValue('RazorpayMappingSchema', self::SCHEMA_VERSION);
            }
        } catch (\Exception $e) {
        }
    }

    public static function findReusable($invoiceId, $amount, $currency, $keyId, $captureMode)
    {
        return Capsule::table(self::TABLE)
            ->where('merchant_order_id', (string) $invoiceId)
            ->where('amount', (int) $amount)
            ->where('currency', strtoupper($currency))
            ->where('status', self::STATUS_CREATED)
            ->where('key_id', (string) $keyId)
            ->where('capture_mode', (string) $captureMode)
            ->orderBy('id', 'desc')
            ->first();
    }

    public static function findByRazorpayOrderId($razorpayOrderId)
    {
        if (!Validator::isOrderId($razorpayOrderId)) {
            return null;
        }

        return Capsule::table(self::TABLE)
            ->where('razorpay_order_id', $razorpayOrderId)
            ->orderBy('id', 'desc')
            ->first();
    }

    public static function findOpenForInvoice($invoiceId, $keyId, $limit = 5)
    {
        return Capsule::table(self::TABLE)
            ->where('merchant_order_id', (string) $invoiceId)
            ->where(function ($query) {
                $query->where('status', self::STATUS_CREATED)->orWhereNull('status');
            })
            ->where(function ($query) use ($keyId) {
                $query->where('key_id', (string) $keyId)->orWhereNull('key_id');
            })
            ->orderBy('id', 'desc')
            ->limit((int) $limit)
            ->get();
    }

    public static function forInvoice($invoiceId, $limit = 5)
    {
        return Capsule::table(self::TABLE)
            ->where('merchant_order_id', (string) $invoiceId)
            ->orderBy('id', 'desc')
            ->limit((int) $limit)
            ->get();
    }

    public static function insert($invoiceId, $razorpayOrderId, $amount, $currency, $invoiceAmount, $keyId, $captureMode)
    {
        if (!Validator::isInvoiceId($invoiceId) || !Validator::isOrderId($razorpayOrderId)) {
            throw new \InvalidArgumentException('Refusing to store invalid invoice/order id.');
        }

        $now = date('Y-m-d H:i:s');

        Capsule::table(self::TABLE)->insert(array(
            'merchant_order_id' => (string) $invoiceId,
            'razorpay_order_id' => $razorpayOrderId,
            'amount'            => (int) $amount,
            'currency'          => strtoupper($currency),
            'invoice_amount'    => $invoiceAmount,
            'status'            => self::STATUS_CREATED,
            'key_id'            => (string) $keyId,
            'capture_mode'      => $captureMode,
            'created_at'        => $now,
            'updated_at'        => $now,
        ));
    }

    public static function markPaid($razorpayOrderId, $paymentId)
    {
        self::markStatus($razorpayOrderId, self::STATUS_PAID, $paymentId);
    }

    public static function markStatus($razorpayOrderId, $status, $paymentId = null)
    {
        $now  = date('Y-m-d H:i:s');
        $data = array('status' => $status, 'updated_at' => $now, 'checked_at' => $now);

        if ($paymentId !== null) {
            $data['razorpay_payment_id'] = $paymentId;
        }

        Capsule::table(self::TABLE)->where('razorpay_order_id', $razorpayOrderId)->update($data);
    }

    public static function touch($razorpayOrderId)
    {
        Capsule::table(self::TABLE)
            ->where('razorpay_order_id', $razorpayOrderId)
            ->update(array('updated_at' => date('Y-m-d H:i:s')));
    }

    public static function markChecked($razorpayOrderId)
    {
        Capsule::table(self::TABLE)
            ->where('razorpay_order_id', $razorpayOrderId)
            ->update(array('checked_at' => date('Y-m-d H:i:s')));
    }

    public static function pendingForReconciliation($keyId, $windowSeconds, $minIdleSeconds, $limit)
    {
        $now      = date('Y-m-d H:i:s');
        $activity = 'COALESCE(m.updated_at, m.created_at)';

        return Capsule::table(self::TABLE . ' as m')
            ->join('tblinvoices as i', 'i.id', '=', 'm.merchant_order_id')
            ->whereIn('i.status', array('Unpaid', 'Payment Pending'))
            ->where('m.status', self::STATUS_CREATED)
            ->where(function ($query) use ($keyId) {
                $query->where('m.key_id', (string) $keyId)->orWhereNull('m.key_id');
            })
            ->whereRaw($activity . ' >= ?', array(date('Y-m-d H:i:s', time() - (int) $windowSeconds)))
            ->whereRaw($activity . ' <= ?', array(date('Y-m-d H:i:s', time() - (int) $minIdleSeconds)))
            ->whereRaw(
                '(m.checked_at IS NULL OR m.checked_at <= DATE_SUB(?, INTERVAL GREATEST(?, TIMESTAMPDIFF(SECOND, ' . $activity . ', ?) DIV 6) SECOND))',
                array($now, (int) $minIdleSeconds, $now)
            )
            ->orderByRaw('m.checked_at IS NULL DESC, m.checked_at ASC, m.id DESC')
            ->limit((int) $limit)
            ->select('m.*')
            ->get();
    }
}
