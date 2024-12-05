<?php

namespace App\Http\Controllers\Api\V1\Webhook\Traits;

use App\Enums\TransactionName;
use App\Enums\WagerStatus;
use App\Http\Requests\Slot\SlotWebhookRequest;
use App\Models\Admin\GameType;
use App\Models\Admin\GameTypeProduct;
use App\Models\Admin\Product;
use App\Models\SeamlessEvent;
use App\Models\User;
use App\Models\Wager;
use App\Services\WalletService;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

trait OptimizedBettingProcess
{

    public function placeBet(SlotWebhookRequest $request)
    {
        $userId = $request->getMember()->id;

        // Acquire Redis lock
        $lock = Redis::set("wallet:lock:$userId", true, 'EX', 15, 'NX'); // 15-second lock
        if (!$lock) {
            return response()->json(['message' => 'The wallet is currently being updated. Please try again later.'], 409);
        }

        DB::beginTransaction();
        try {
            // Validate the request
            $validator = $request->check();
            if ($validator->fails()) {
                Redis::del("wallet:lock:$userId");
                return $validator->getResponse();
            }

            $beforeBalance = $request->getMember()->balanceFloat;
            $event = $this->createEvent($request);

            // Insert bets in chunks for better performance
            $this->insertBets($validator->getRequestTransactions(), $event);

            DB::commit();

        } catch (Exception $e) {
            DB::rollBack();
            Redis::del("wallet:lock:$userId");
            Log::error('Error during placeBet', ['error' => $e->getMessage()]);
            return response()->json(['message' => $e->getMessage()], 500);
        }

        // Process wallet updates separately
        try {
            foreach ($validator->getRequestTransactions() as $transaction) {
                $fromUser = $request->getMember();
                $toUser = User::adminUser();

                // Fetch rate and meta info
                $gameType = GameType::where('code', $transaction->GameType)->first();
                $product = Product::where('code', $transaction->ProductID)->first();
                $gameTypeProduct = GameTypeProduct::where('game_type_id', $gameType->id)
                    ->where('product_id', $product->id)
                    ->first();
                $rate = (int) ($gameTypeProduct->rate ?? 1);

                $meta = [
                    'wager_id' => $transaction->WagerID,
                    'event_id' => $request->getMessageID(),
                    'seamless_transaction_id' => $transaction->TransactionID,
                ];

                // Process the transfer
                $this->processTransfer(
                    $fromUser,
                    $toUser,
                    TransactionName::Stake,
                    $transaction->TransactionAmount,
                    $rate,
                    $meta
                );
            }

            // Refresh balance after all transactions
            $request->getMember()->wallet->refreshBalance();
            $afterBalance = $request->getMember()->balanceFloat;

        } catch (Exception $e) {
            Log::error('Error during wallet transfer processing', ['error' => $e->getMessage()]);
            Redis::del("wallet:lock:$userId");
            return response()->json(['message' => $e->getMessage()], 500);
        }

        Redis::del("wallet:lock:$userId");

        return response()->json([
            'balance_before' => $beforeBalance,
            'balance_after' => $afterBalance,
            'message' => 'Bet placed successfully.',
        ], 200);
    }

    public function insertBets(array $bets, SeamlessEvent $event)
    {
        $chunkSize = 50;
        $batches = array_chunk($bets, $chunkSize);

        DB::transaction(function () use ($batches, $event) {
            foreach ($batches as $batch) {
                $this->createWagerTransactions($batch, $event);
            }
        });
    }

    public function createWagerTransactions(array $betBatch, SeamlessEvent $event)
    {
        $wagerData = [];
        $seamlessTransactionsData = [];
        $userId = $event->user_id;
        $seamlessEventId = $event->id;

        foreach ($betBatch as $transaction) {
            if (!$transaction->TransactionID || !$transaction->WagerID) {
                throw new Exception('Invalid TransactionID or WagerID');
            }

            $existingWager = Wager::where('seamless_wager_id', $transaction->WagerID)
                ->lockForUpdate()
                ->first();

            if (!$existingWager) {
                $wagerData[] = [
                    'user_id' => $userId,
                    'seamless_wager_id' => $transaction->WagerID,
                    'status' => $transaction->TransactionAmount > 0 ? WagerStatus::Win : WagerStatus::Lose,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            $seamlessTransactionsData[] = [
                'user_id' => $userId,
                'wager_id' => $existingWager ? $existingWager->id : null,
                'game_type_id' => $transaction->GameType,
                'product_id' => $transaction->ProductID,
                'seamless_transaction_id' => $transaction->TransactionID,
                'rate' => 1, // Default rate
                'transaction_amount' => $transaction->TransactionAmount,
                'bet_amount' => $transaction->BetAmount,
                'valid_amount' => $transaction->ValidBetAmount,
                'status' => $transaction->Status,
                'seamless_event_id' => $seamlessEventId,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (!empty($wagerData)) {
            DB::table('wagers')->insert($wagerData);
        }

        if (!empty($seamlessTransactionsData)) {
            DB::table('seamless_transactions')->insert($seamlessTransactionsData);
        }
    }


    public function processTransfer(User $from, User $to, TransactionName $transactionName, float $amount, int $rate, array $meta)
    {
        $retryCount = 0;
        $maxRetries = 5;

        do {
            try {
                // Only lock the necessary rows inside the transaction
                DB::transaction(function () use ($from, $to, $amount, $transactionName, $meta) {
                    // Lock only the specific rows for the wallet that needs updating
                    $walletFrom = $from->wallet()->lockForUpdate()->firstOrFail();
                    $walletTo = $to->wallet()->lockForUpdate()->firstOrFail();

                    // Update wallet balances
                    $walletFrom->balance -= $amount;
                    $walletTo->balance += $amount;

                    // Save the updated balances
                    $walletFrom->save();
                    $walletTo->save();

                    // Perform the transfer in the wallet service (possibly outside the transaction)
                    app(WalletService::class)->transfer($from, $to, abs($amount), $transactionName, $meta);
                });

                break;  // Exit loop if successful

            } catch (\Illuminate\Database\QueryException $e) {
                if ($e->getCode() === '40001') {  // Deadlock error code
                    $retryCount++;
                    if ($retryCount >= $maxRetries) {
                        throw $e;  // Max retries reached, fail
                    }
                    sleep(1);  // Wait before retrying
                } else {
                    throw $e;  // Rethrow non-deadlock exceptions
                }
            }
        } while ($retryCount < $maxRetries);
    }

    /**
     * Retry logic for handling deadlocks with exponential backoff.
     */
    private function retryOnDeadlock(callable $callback, $maxRetries = 5)
    {
        $retryCount = 0;

        do {
            try {
                return $callback();
            } catch (\Illuminate\Database\QueryException $e) {
                if ($e->getCode() === '40001') {  // Deadlock error code
                    $retryCount++;
                    if ($retryCount >= $maxRetries) {
                        throw $e;  // Max retries reached, fail
                    }
                    sleep(pow(2, $retryCount));  // Exponential backoff
                } else {
                    throw $e;  // Rethrow non-deadlock exceptions
                }
            }
        } while ($retryCount < $maxRetries);
    }

    /**
     * Create the event in the system.
     */
    public function createEvent(SlotWebhookRequest $request): SeamlessEvent
    {
        return SeamlessEvent::create([
            'user_id' => $request->getMember()->id,
            'message_id' => $request->getMessageID(),
            'product_id' => $request->getProductID(),
            'request_time' => $request->getRequestTime(),
            'raw_data' => $request->all(),
        ]);
    }
}