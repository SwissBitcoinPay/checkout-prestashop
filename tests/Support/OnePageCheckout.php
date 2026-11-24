<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * PrestaShop 9.2's One Page Checkout (its module ps_onepagecheckout, once the merchant turns it on),
 * driven over HTTP as its script drives it: its page, then its JSON endpoints in the order the
 * script calls them (the guest's account made from the email typed, the address saved as typed,
 * the carriers, the payment methods), then "Place order": the whole checkout form submitted to its
 * endpoint, which saves the guest, the address and the carrier, before the payment method's own
 * form is posted. The shop's static token goes with each request, as the script sends it.
 */
final class OnePageCheckout
{
    /** @var array<string, string> the endpoints, by their names in the module's runtime */
    private array $urls = [];

    private string $token;

    public function __construct(private readonly Browser $browser, public readonly Page $page)
    {
        if (preg_match('/var ps_onepagecheckout = (\{.*?\});\s*\n/s', $page->body, $runtime) !== 1) {
            Assert::fail('No one page checkout in ' . $page->url . ': ' . substr($page->text(), 0, 300));
        }
        $decoded = json_decode($runtime[1], true);
        $urls = is_array($decoded) && is_array($decoded['urls'] ?? null) ? $decoded['urls'] : [];
        foreach ($urls as $name => $url) {
            if (is_string($url)) {
                $this->urls[(string) $name] = Page::resolve($page->url, $url);
            }
        }
        if (preg_match('/"static_token":"([0-9a-f]+)"/', $page->body, $token) !== 1) {
            Assert::fail('No static token in ' . $page->url);
        }
        $this->token = $token[1];
    }

    /** The checkout's form, as its page shows it. */
    public function form(): Form
    {
        return $this->page->form(static fn (Form $form): bool => $form->id === 'opc-form', 'of the one page checkout');
    }

    /** The guest's account, made from the email typed (and the consents asked): its id. */
    public function guest(string $email): int
    {
        $answer = $this->call('guestInit', ['email' => $email, 'static_token' => $this->token, 'psgdpr' => '1', 'customer_privacy' => '1', 'optin' => '0', 'newsletter' => '0']);
        Assert::assertTrue($answer['customer_created'] ?? null, (string) json_encode($answer));
        // The session's static token changes with its customer.
        $token = $answer['static_token'] ?? null;
        Assert::assertIsString($token);
        $this->token = $token;
        $id = $answer['id_customer'] ?? null;
        Assert::assertIsInt($id);

        return $id;
    }

    /**
     * The delivery address, as typed (also the invoice's), saved once complete: its id.
     *
     * @param array<string, string> $address
     */
    public function address(array $address): int
    {
        $answer = $this->call('saveDraft', $address + ['address_type' => 'delivery', 'use_same_address' => '1']);
        Assert::assertTrue($answer['address_persisted'] ?? null, (string) json_encode($answer));
        $id = $answer['id_address_delivery'] ?? null;
        Assert::assertIsInt($id);

        return $id;
    }

    /** The carriers of that address, and the one shown selected (else the first) selected: its option. */
    public function carrier(int $idAddress): string
    {
        $carriers = $this->call('carriers', ['id_address_delivery' => (string) $idAddress, 'use_same_address' => '1']);
        $options = $carriers['delivery_options'] ?? null;
        Assert::assertIsArray($options);
        Assert::assertNotSame([], $options, 'a carrier for the address');
        $option = $carriers['delivery_option'] ?? '';
        $option = is_string($option) && $option !== '' ? $option : (string) array_key_first($options);
        $this->call('selectCarrier', ['delivery_option' => $option, 'id_address_delivery' => (string) $idAddress, 'use_same_address' => '1']);

        return $option;
    }

    /** The payment methods of the checkout, $module's chosen: its form, as the page then shows it. */
    public function paymentForm(string $module, int $idAddress): Form
    {
        $methods = $this->paymentMethods($idAddress);
        $radio = null;
        foreach ($methods->byName('input', 'payment-option') as $input) {
            if ($input->getAttribute('data-module-name') === $module) {
                $radio = $input;
            }
        }
        Assert::assertNotNull($radio, "{$module} among the payment methods");
        $this->call('selectPayment', [
            'payment_option' => $radio->getAttribute('value'),
            'payment_module' => $module,
            'payment_selection_key' => $radio->getAttribute('data-selection-key'),
        ]);

        // Its form's fields, once it is the one chosen (the page's script enables them).
        return HttpShop::validationForm($this->paymentMethods($idAddress));
    }

    /**
     * "Place order": the checkout's form submitted to its endpoint, as the script sends it before
     * it posts the payment method's form. Its answer.
     *
     * @param array<string, string> $values the fields the customer filled in
     *
     * @return array<string, mixed>
     */
    public function submit(array $values): array
    {
        // The script leaves the addresses' technical ids out: the fields typed are authoritative.
        $form = $this->form()->without('id_address_delivery', 'id_address_invoice')->with($values + ['static_token' => $this->token]);

        return $this->json($this->browser->post($this->endpoint('opcSubmit'), $form->fields()));
    }

    /** The payment methods, as their section of the page shows them. */
    private function paymentMethods(int $idAddress): Page
    {
        $answer = $this->call('paymentMethods', ['id_address_delivery' => (string) $idAddress]);
        $html = $answer['payment_html'] ?? null;
        Assert::assertIsString($html);

        return new Page($this->page->url, 200, [], '<!DOCTYPE html><html><body>' . $html . '</body></html>');
    }

    /**
     * A JSON endpoint of the checkout, its answer a success.
     *
     * @param array<string, string> $fields
     *
     * @return array<string, mixed>
     */
    private function call(string $endpoint, array $fields): array
    {
        $answer = $this->json($this->browser->post($this->endpoint($endpoint), $fields + ['token' => $this->token]));
        Assert::assertTrue($answer['success'] ?? null, "{$endpoint}: " . substr((string) json_encode($answer), 0, 1000));

        return $answer;
    }

    private function endpoint(string $name): string
    {
        $url = $this->urls[$name] ?? null;
        Assert::assertIsString($url, "the endpoint {$name}");

        return $url;
    }

    /** @return array<string, mixed> */
    private function json(Page $answer): array
    {
        Assert::assertSame(200, $answer->status, substr($answer->body, 0, 500));

        return $answer->json();
    }
}
