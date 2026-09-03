<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Stripe\Invoice as StripeInvoice;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_list_invoices(): void
    {
        $this->getJson('/api/invoices')->assertUnauthorized();
    }

    public function test_master_can_list_invoices_with_pdf_urls(): void
    {
        $user = User::factory()->create([
            'stripe_customer_id' => 'cus_test_123',
        ]);
        Sanctum::actingAs($user);

        $this->mock(StripeService::class, function (MockInterface $mock) {
            $mock->shouldReceive('listInvoicesForUser')
                ->once()
                ->andReturn([
                    'invoices' => [
                        [
                            'id' => 'in_test_mensual',
                            'number' => 'ARD-0001',
                            'status' => 'paid',
                            'currency' => 'mxn',
                            'amount_due_cents' => 59900,
                            'amount_paid_cents' => 59900,
                            'amount_due' => 599.0,
                            'amount_paid' => 599.0,
                            'created_at' => '2026-09-01T12:00:00+00:00',
                            'period_start' => '2026-09-01T12:00:00+00:00',
                            'period_end' => '2026-10-01T12:00:00+00:00',
                            'paid_at' => '2026-09-01T12:05:00+00:00',
                            'description' => 'Suscripción Standard',
                            'plan' => 'basico_mensual',
                            'billing_period' => 'mensual',
                            'invoice_pdf' => 'https://pay.stripe.com/invoice/in_test_mensual/pdf',
                            'hosted_invoice_url' => 'https://invoice.stripe.com/i/acct_test/in_test_mensual',
                        ],
                    ],
                    'has_more' => false,
                ]);
        });

        $this->getJson('/api/invoices')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.invoices.0.id', 'in_test_mensual')
            ->assertJsonPath('data.invoices.0.billing_period', 'mensual')
            ->assertJsonPath('data.invoices.0.invoice_pdf', 'https://pay.stripe.com/invoice/in_test_mensual/pdf')
            ->assertJsonPath('data.has_more', false);
    }

    public function test_user_without_stripe_customer_gets_empty_list(): void
    {
        $user = User::factory()->create([
            'stripe_customer_id' => null,
        ]);
        Sanctum::actingAs($user);

        $this->mock(StripeService::class, function (MockInterface $mock) {
            $mock->shouldReceive('listInvoicesForUser')
                ->once()
                ->andReturn([
                    'invoices' => [],
                    'has_more' => false,
                ]);
        });

        $this->getJson('/api/invoices')
            ->assertOk()
            ->assertJsonPath('data.invoices', [])
            ->assertJsonPath('data.has_more', false);
    }

    public function test_master_can_show_invoice_detail(): void
    {
        $user = User::factory()->create([
            'stripe_customer_id' => 'cus_test_123',
        ]);
        Sanctum::actingAs($user);

        $this->mock(StripeService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getInvoiceForUser')
                ->once()
                ->withArgs(fn ($u, $id) => $id === 'in_test_anual')
                ->andReturn([
                    'id' => 'in_test_anual',
                    'number' => 'ARD-0002',
                    'status' => 'paid',
                    'currency' => 'mxn',
                    'amount_due' => 5999.0,
                    'amount_paid' => 5999.0,
                    'billing_period' => 'anual',
                    'invoice_pdf' => 'https://pay.stripe.com/invoice/in_test_anual/pdf',
                    'hosted_invoice_url' => 'https://invoice.stripe.com/i/acct_test/in_test_anual',
                ]);
        });

        $this->getJson('/api/invoices/in_test_anual')
            ->assertOk()
            ->assertJsonPath('data.invoice.id', 'in_test_anual')
            ->assertJsonPath('data.invoice.billing_period', 'anual')
            ->assertJsonPath('data.invoice.invoice_pdf', 'https://pay.stripe.com/invoice/in_test_anual/pdf');
    }

    public function test_map_invoice_exposes_display_status_for_paid_open_and_refunded(): void
    {
        $service = app(StripeService::class);

        $paid = $service->mapInvoice(StripeInvoice::constructFrom([
            'id' => 'in_paid',
            'status' => 'paid',
            'currency' => 'mxn',
            'amount_due' => 59900,
            'amount_paid' => 59900,
            'created' => 1725300000,
            'period_start' => 1725300000,
            'period_end' => 1727892000,
            'status_transitions' => ['paid_at' => 1725300100],
            'post_payment_credit_notes_amount' => 0,
            'charge' => [
                'id' => 'ch_paid',
                'amount_refunded' => 0,
                'refunded' => false,
            ],
            'lines' => ['data' => []],
        ]));

        $this->assertSame('paid', $paid['status']);
        $this->assertSame('paid', $paid['display_status']);
        $this->assertSame('Pagada', $paid['estatus']);

        $open = $service->mapInvoice(StripeInvoice::constructFrom([
            'id' => 'in_open',
            'status' => 'open',
            'currency' => 'mxn',
            'amount_due' => 59900,
            'amount_paid' => 0,
            'created' => 1725300000,
            'post_payment_credit_notes_amount' => 0,
            'lines' => ['data' => []],
        ]));

        $this->assertSame('open', $open['display_status']);
        $this->assertSame('En proceso de pago', $open['estatus']);

        $refunded = $service->mapInvoice(StripeInvoice::constructFrom([
            'id' => 'in_refunded',
            'status' => 'paid',
            'currency' => 'mxn',
            'amount_due' => 59900,
            'amount_paid' => 59900,
            'created' => 1725300000,
            'post_payment_credit_notes_amount' => 0,
            'charge' => [
                'id' => 'ch_ref',
                'amount_refunded' => 59900,
                'refunded' => true,
                'refunds' => [
                    'data' => [
                        ['id' => 're_1', 'status' => 'succeeded', 'amount' => 59900],
                    ],
                ],
            ],
            'lines' => ['data' => []],
        ]));

        $this->assertSame('paid', $refunded['status']);
        $this->assertSame('refunded', $refunded['display_status']);
        $this->assertSame('Reembolsada', $refunded['estatus']);
        $this->assertTrue($refunded['refunded']);

        $pendingRefund = $service->mapInvoice(StripeInvoice::constructFrom([
            'id' => 'in_pending_refund',
            'status' => 'paid',
            'currency' => 'mxn',
            'amount_due' => 59900,
            'amount_paid' => 59900,
            'created' => 1725300000,
            'post_payment_credit_notes_amount' => 0,
            'charge' => [
                'id' => 'ch_pending',
                'amount_refunded' => 0,
                'refunded' => false,
                'refunds' => [
                    'data' => [
                        ['id' => 're_pending', 'status' => 'pending', 'amount' => 59900],
                    ],
                ],
            ],
            'lines' => ['data' => []],
        ]));

        $this->assertSame('refund_pending', $pendingRefund['display_status']);
        $this->assertSame('En proceso de reembolso', $pendingRefund['estatus']);

        $basilRefunded = $service->mapInvoice(StripeInvoice::constructFrom([
            'id' => 'in_basil_refunded',
            'status' => 'paid',
            'currency' => 'mxn',
            'amount_due' => 59900,
            'amount_paid' => 59900,
            'created' => 1725300000,
            'post_payment_credit_notes_amount' => 0,
            'payments' => [
                'data' => [
                    [
                        'status' => 'paid',
                        'payment' => [
                            'payment_intent' => [
                                'id' => 'pi_refunded',
                                'latest_charge' => [
                                    'id' => 'ch_basil',
                                    'amount_refunded' => 59900,
                                    'refunded' => true,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'lines' => ['data' => []],
        ]));

        $this->assertSame('refunded', $basilRefunded['display_status']);
        $this->assertSame('Reembolsada', $basilRefunded['estatus']);
        $this->assertTrue($basilRefunded['refunded']);
        $this->assertSame(599.0, $basilRefunded['amount_refunded']);
    }
}
