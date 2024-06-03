<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Webkul\Checkout\Facades\Cart;
use Webkul\Customer\Repositories\CustomerRepository;
use Webkul\Payment\Facades\Payment;
use Webkul\Sales\Repositories\OrderRepository;
use Webkul\Shipping\Facades\Shipping;
use Webkul\Shop\Http\Requests\CartAddressRequest;
use Webkul\Shop\Http\Resources\CartResource;
use Mpesa;
use Illuminate\Http\Request;
use phpDocumentor\Reflection\Types\This;
use Illuminate\Support\Facades\DB;

class OnepageController extends APIController
{
 
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(
        protected OrderRepository $orderRepository,
        protected CustomerRepository $customerRepository
    ) {
    }

    /**
     * Return cart summary.
     */
    public function summary(): JsonResource
    {
        $cart = Cart::getCart();

        return new CartResource($cart);
    }

    /**
     * Store address.
     */
    public function storeAddress(CartAddressRequest $cartAddressRequest): JsonResource
    {
        $params = $cartAddressRequest->all();

        if (
            !auth()->guard('customer')->check()
            && !Cart::getCart()->hasGuestCheckoutItems()
        ) {
            return new JsonResource([
                'redirect' => true,
                'data'     => route('shop.customer.session.index'),
            ]);
        }

        if (Cart::hasError()) {
            return new JsonResource([
                'redirect'     => true,
                'redirect_url' => route('shop.checkout.cart.index'),
            ]);
        }

        Cart::saveAddresses($params);

        $cart = Cart::getCart();

        Cart::collectTotals();

        if ($cart->haveStockableItems()) {
            if (!$rates = Shipping::collectRates()) {
                return new JsonResource([
                    'redirect'     => true,
                    'redirect_url' => route('shop.checkout.cart.index'),
                ]);
            }

            return new JsonResource([
                'redirect' => false,
                'data'     => $rates,
            ]);
        }

        return new JsonResource([
            'redirect' => false,
            'data'     => Payment::getSupportedPaymentMethods(),
        ]);
    }

    /**
     * Store shipping method.
     *
     * @return \Illuminate\Http\Response
     */
    public function storeShippingMethod()
    {
        $validatedData = $this->validate(request(), [
            'shipping_method' => 'required',
        ]);

        if (
            Cart::hasError()
            || !$validatedData['shipping_method']
            || !Cart::saveShippingMethod($validatedData['shipping_method'])
        ) {
            return response()->json([
                'redirect_url' => route('shop.checkout.cart.index'),
            ], Response::HTTP_FORBIDDEN);
        }

        Cart::collectTotals();

        return response()->json(Payment::getSupportedPaymentMethods());
    }

    /**
     * Store payment method.
     *
     * @return array
     */

    // public $myPayMethod;
    // public $myCart;

    public function storePaymentMethod()
    {
        // dd($request->payment['method']);
        // dd($request->all());
        
        $validatedData = $this->validate(request(), [
            'payment' => 'required',
        ]);

        if (
            Cart::hasError()
            || !$validatedData['payment']
            || !Cart::savePaymentMethod($validatedData['payment'])
        ) {
            return response()->json([
                'redirect_url' => route('shop.checkout.cart.index'),
            ], Response::HTTP_FORBIDDEN);
        }

        Cart::collectTotals();

        $cart = Cart::getCart();

        // $this->myPayMethod=request()->payment['method'];
        
        // $this->myCart=new CartResource($cart);
        

        return [
            'cart' => new CartResource($cart),
            // 'payment_method'=>request()->payment['method'],
        ];
        
    }
    /**
     * mpesa stk push function
    */
    public function stkPush($amt,$phone)
    {
        $mpesa = new \Safaricom\Mpesa\Mpesa();
        $BusinessShortCode = '174379';
        $LipaNaMpesaPasskey = 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919';
        $TransactionType = 'CustomerPayBillOnline';
        $Amount = $amt;
        $PartyA = $phone;
        $PartyB = '174379';
        $PhoneNumber = $phone;
        $CallBackURL = 'https://yourdomain.com/mpesa/confirmation';
        $AccountReference = 'Account 002';
        $TransactionDesc = 'TransactionDesc';
        $Remarks = 'Remarks';

        
        $stkPushSimulation = $mpesa->STKPushSimulation(
            $BusinessShortCode,
            $LipaNaMpesaPasskey,
            $TransactionType,
            $Amount,
            $PartyA,
            $PartyB,
            $PhoneNumber,
            $CallBackURL,
            $AccountReference,
            $TransactionDesc,
            $Remarks
        );
    }
    /**
     * Store order
     */

    
    public function storeOrder()
    {
        // $stkPushSimulation();

        if (Cart::hasError()) {
            return new JsonResource([
                'redirect'     => true,
                'redirect_url' => route('shop.checkout.cart.index'),
            ]);
        }

        Cart::collectTotals();
        

        try {
            $this->validateOrder();
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }

        $cart = Cart::getCart();

        
        // dd($cart);
        // dd(var_dump($cart["grand_total"]));
        if($cart["payment"]["method"] == "moneytransfer"){
            $cart    = Cart::getCart();
            $address = DB::table('addresses')
             ->where('customer_id', $cart['customer_id'])
             ->orderBy('id', 'desc') // Assuming 'id' is the primary key or an incrementing column
             ->first();
            //  dd($address);
            $this->stkPush($cart["grand_total"],$address->phone);
        }

        
        // dd(var_dump($cart["payment"]["method"] == "moneytransfer"));
        // dd($cart["payment"]["method"]);
        // if($cart["payment"]["method"]=="moneytransfer"){
        //     dd("Trigger stk push");
        // }

        if ($redirectUrl = Payment::getRedirectUrl($cart)) {
            return new JsonResource([
                'redirect'     => true,
                'redirect_url' => $redirectUrl,
            ]);
        }

        $order = $this->orderRepository->create(Cart::prepareDataForOrder());
        // dd($order);
        Cart::deActivateCart();

        Cart::activateCartIfSessionHasDeactivatedCartId();

        session()->flash('order', $order);

        return new JsonResource([
            'redirect'     => true,
            'redirect_url' => route('shop.checkout.onepage.success'),
        ]);
    }

    /**
     * Check for minimum order.
     *
     * @return \Illuminate\Http\Response
     */
    public function checkMinimumOrder()
    {
        $minimumOrderAmount = (float) core()->getConfigData('sales.order_settings.minimum_order.minimum_order_amount') ?: 0;

        $status = Cart::checkMinimumOrder();

        return response()->json([
            'status'  => !$status ? false : true,
            'message' => !$status
                ? trans('shop::app.checkout.cart.minimum-order-message', [
                    'amount' => core()->currency($minimumOrderAmount),
                ])
                : 'Success',
        ]);
    }

    /**
     * Validate order before creation.
     *
     * @return void|\Exception
     */
    public function validateOrder()
    {
        $cart = Cart::getCart();

        $minimumOrderAmount = core()->getConfigData('sales.order_settings.minimum_order.minimum_order_amount') ?: 0;

        if (
            auth()->guard('customer')->check()
            && auth()->guard('customer')->user()->is_suspended
        ) {
            throw new \Exception(trans('shop::app.checkout.cart.suspended-account-message'));
        }

        if (
            auth()->guard('customer')->user()
            && !auth()->guard('customer')->user()->status
        ) {
            throw new \Exception(trans('shop::app.checkout.cart.inactive-account-message'));
        }

        if (!$cart->checkMinimumOrder()) {
            throw new \Exception(trans('shop::app.checkout.cart.minimum-order-message', ['amount' => core()->currency($minimumOrderAmount)]));
        }

        if ($cart->haveStockableItems() && !$cart->shipping_address) {
            throw new \Exception(trans('shop::app.checkout.cart.check-shipping-address'));
        }

        if (!$cart->billing_address) {
            throw new \Exception(trans('shop::app.checkout.cart.check-billing-address'));
        }

        if (
            $cart->haveStockableItems()
            && !$cart->selected_shipping_rate
        ) {
            throw new \Exception(trans('shop::app.checkout.cart.specify-shipping-method'));
        }

        if (!$cart->payment) {
            throw new \Exception(trans('shop::app.checkout.cart.specify-payment-method'));
        }
    }
}