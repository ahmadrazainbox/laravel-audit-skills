<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function export(): StreamedResponse
    {
        // FLAW: hydrates every order in the table into memory before writing
        // a single byte. At ~500k rows this exhausts memory_limit.
        $orders = Order::all();

        return response()->streamDownload(function () use ($orders) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'customer', 'total', 'placed_at']);

            foreach ($orders as $order) {
                // FLAW: lazy-loads the customer relation once per row.
                fputcsv($out, [
                    $order->id,
                    $order->customer->name,
                    $order->total_cents / 100,
                    $order->placed_at,
                ]);
            }

            fclose($out);
        }, 'orders.csv');
    }

    public function monthlyTotals()
    {
        // FLAW: loads and sums in PHP what the database can aggregate.
        $orders = Order::where('status', 'paid')->orderBy('placed_at')->get();

        $totals = [];
        foreach ($orders as $order) {
            $month = substr((string) $order->placed_at, 0, 7);
            $totals[$month] = ($totals[$month] ?? 0) + $order->total_cents;
        }

        return $totals;
    }
}
