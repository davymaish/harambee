<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Category;
use App\Models\Country;
use App\Models\Payment;
use App\Models\Reward;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Intervention\Image\Facades\Image;

class CheckoutController extends Controller
{

    /**
     * @param Request $request
     * @param int $reward_id
     * @return mixed
     */
    public function addToCart(Request $request, $reward_id = 0){
        if ($reward_id){
            //If checkout request come from reward
            session( ['cart' =>  ['cart_type' => 'reward', 'reward_id' => $reward_id] ] );

            $reward = Reward::find($reward_id);
            if($reward->campaign->is_ended()){
                $request->session()->forget('cart');
                return redirect()->back()->with('error', trans('app.invalid_request'));
            }
        }else{
            //Or if comes from donate button
            session( ['cart' =>  ['cart_type' => 'donation', 'campaign_id' => $request->campaign_id, 'amount' => $request->amount ] ] );
        }


        return redirect(route('checkout'));
    }

    /**
     * @return mixed
     *
     * Checkout page
     */
    public function checkout(){
        $title = trans('app.checkout');

        if ( ! session('cart')){
            return view('public.checkout.empty', compact('title'));
        }

        $reward = null;
        if(session('cart.cart_type') == 'reward'){
            $reward = Reward::find(session('cart.reward_id'));
            $campaign = Campaign::find($reward->campaign_id);
        }elseif (session('cart.cart_type') == 'donation'){
            $campaign = Campaign::find(session('cart.campaign_id'));
        }
        if (session('cart')){
            return view('public.checkout.index', compact('title', 'campaign', 'reward'));
        }
        return view('public.checkout.empty', compact('title'));
    }

    public function checkoutPost(Request $request){
        $title = trans('app.checkout');

        if ( ! session('cart')){
            return view('public.checkout.empty', compact('title'));
        }

        $cart = session('cart');
        $input = array_except($request->input(), '_token');
        session(['cart' => array_merge($cart, $input)]);

        if(session('cart.cart_type') == 'reward'){
            $reward = Reward::find(session('cart.reward_id'));
            $campaign = Campaign::find($reward->campaign_id);
        }elseif (session('cart.cart_type') == 'donation'){
            $campaign = Campaign::find(session('cart.campaign_id'));
        }
        
        //dd(session('cart'));
        return view('public.checkout.payment', compact('title', 'campaign'));
    }

    /**
     * @param Request $request
     * @return mixed
     *
     * Payment gateway PayPal
     */
    public function paypalRedirect(Request $request){
        $title = trans('app.checkout');
        if ( ! session('cart')){
            return view('public.checkout.empty', compact('title'));
        }
        //Find the campaign
        $cart = session('cart');

        $amount = 0;
        if(session('cart.cart_type') == 'reward'){
            $reward = Reward::find(session('cart.reward_id'));
            $amount = $reward->amount;
            $campaign = Campaign::find($reward->campaign_id);
        }elseif (session('cart.cart_type') == 'donation'){
            $campaign = Campaign::find(session('cart.campaign_id'));
            $amount = $cart['amount'];
        }
        $currency = get_option('currency_sign');
        $user_id = null;
        if (Auth::check()){
            $user_id = Auth::user()->id;
        }
        //Create payment in database


        $transaction_id = 'tran_'.time().str_random(6);
        // get unique recharge transaction id
        while( ( Payment::whereLocalTransactionId($transaction_id)->count() ) > 0) {
            $transaction_id = 'reid'.time().str_random(5);
        }
        $transaction_id = strtoupper($transaction_id);

        $payments_data = [
            'name' => session('cart.full_name'),
            'email' => session('cart.email'),

            'user_id'               => $user_id,
            'campaign_id'           => $campaign->id,
            'reward_id'             => session('cart.reward_id'),

            'amount'                => $amount,
            'payment_method'        => 'paypal',
            'status'                => 'initial',
            'currency'              => $currency,
            'local_transaction_id'  => $transaction_id,

            'contributor_name_display'  => session('cart.contributor_name_display'),
        ];
        //Create payment and clear it from session
        $created_payment = Payment::create($payments_data);
        $request->session()->forget('cart');

        // PayPal settings
        $paypal_action_url = "https://www.paypal.com/cgi-bin/webscr";
        if (get_option('enable_paypal_sandbox') == 1)
            $paypal_action_url = "https://www.sandbox.paypal.com/cgi-bin/webscr";

        $paypal_email = get_option('paypal_receiver_email');
        $return_url = route('payment_success',$transaction_id);
        $cancel_url = route('checkout');
        $notify_url = route('paypal_notify', $transaction_id);

        $item_name = $campaign->title." [Contributing]";

        // Check if paypal request or response
        $querystring = '';

        // Firstly Append paypal account to querystring
        $querystring .= "?business=".urlencode($paypal_email)."&";

        // Append amount& currency (£) to quersytring so it cannot be edited in html
        //The item name and amount can be brought in dynamically by querying the $_POST['item_number'] variable.
        $querystring .= "item_name=".urlencode($item_name)."&";
        $querystring .= "amount=".urlencode($amount)."&";
        $querystring .= "currency_code=".urlencode($currency)."&";

        $querystring .= "first_name=".urlencode(session('cart.full_name'))."&";
        //$querystring .= "last_name=".urlencode($ad->user->last_name)."&";
        $querystring .= "payer_email=".urlencode(session('cart.email') )."&";
        $querystring .= "item_number=".urlencode($created_payment->local_transaction_id)."&";

        //loop for posted values and append to querystring
        foreach(array_except($request->input(), '_token') as $key => $value){
            $value = urlencode(stripslashes($value));
            $querystring .= "$key=$value&";
        }

        // Append paypal return addresses
        $querystring .= "return=".urlencode(stripslashes($return_url))."&";
        $querystring .= "cancel_return=".urlencode(stripslashes($cancel_url))."&";
        $querystring .= "notify_url=".urlencode($notify_url);

        // Append querystring with custom field
        //$querystring .= "&custom=".USERID;

        // Redirect to paypal IPN
        header('location:'.$paypal_action_url.$querystring);
        exit();
    }

    /**
     * @param Request $request
     * @param $transaction_id
     *
     * Check paypal notify
     */
    public function paypalNotify(Request $request, $transaction_id){
        //todo: need to  be check
        $payment = Payment::whereLocalTransactionId($transaction_id)->where('status','!=','success')->first();

        $verified = paypal_ipn_verify();
        if ($verified){
            //Payment success, we are ready approve your payment
            $payment->status = 'success';
            $payment->charge_id_or_token = $request->txn_id;
            $payment->description = $request->item_name;
            $payment->payer_email = $request->payer_email;
            $payment->payment_created = strtotime($request->payment_date);
            $payment->save();

            //Update totals
            $payment->campaign->updateTotalNow();
        }else{
            $payment->status = 'declined';
            $payment->description = trans('app.payment_declined_msg');
            $payment->save();
        }
        // Reply with an empty 200 response to indicate to paypal the IPN was received correctly
        header("HTTP/1.1 200 OK");
    }


    /**
     * @return array
     * 
     * receive card payment from stripe
     */
    public function paymentStripeReceive(Request $request){

        $user_id = null;
        if (Auth::check()){
            $user_id = Auth::user()->id;
        }

        $stripeToken = $request->stripeToken;
        \Stripe\Stripe::setApiKey(get_stripe_key('secret'));
        // Create the charge on Stripe's servers - this will charge the user's card
        try {
            $cart = session('cart');

            //Find the campaign
            $amount = 0;
            if(session('cart.cart_type') == 'reward'){
                $reward = Reward::find(session('cart.reward_id'));
                $amount = $reward->amount;
                $campaign = Campaign::find($reward->campaign_id);
            }elseif (session('cart.cart_type') == 'donation'){
                $campaign = Campaign::find(session('cart.campaign_id'));
                $amount = $cart['amount'];
            }
            $currency = get_option('currency_sign');

            //Charge from card
            $charge = \Stripe\Charge::create(array(
                "amount"        => get_stripe_amount($amount), // amount in cents, again
                "currency"      => $currency,
                "source"        => $stripeToken,
                "description"   => $campaign->title." [Contributing]"
            ));

            if ($charge->status == 'succeeded'){
                //Save payment into database
                $data = [
                    'name' => session('cart.full_name'),
                    'email' => session('cart.email'),
                    'amount' => get_stripe_amount($charge->amount, 'to_dollar'),

                    'user_id'               => $user_id,
                    'campaign_id'           => $campaign->id,
                    'reward_id'             => session('cart.reward_id'),
                    'payment_method'        => 'stripe',
                    'currency'              => $currency,
                    'charge_id_or_token'    => $charge->id,
                    'description'           => $charge->description,
                    'payment_created'       => $charge->created,

                    //Card Info
                    'card_last4'        => $charge->source->last4,
                    'card_id'           => $charge->source->id,
                    'card_brand'        => $charge->source->brand,
                    'card_country'      => $charge->source->US,
                    'card_exp_month'    => $charge->source->exp_month,
                    'card_exp_year'     => $charge->source->exp_year,

                    'contributor_name_display'  => session('cart.contributor_name_display'),
                    'status'                    => 'success',
                ];

                Payment::create($data);
                $campaign->updateTotalNow();

                $request->session()->forget('cart');
                return ['success'=>1, 'msg'=> trans('app.payment_received_msg'), 'response' => $this->payment_success_html()];
            }
        } catch(\Stripe\Error\Card $e) {
            // The card has been declined
            return ['success'=>0, 'msg'=> trans('app.payment_declined_msg'), 'response' => $e];
        }
    }
    
    public function payment_success_html(){
        $html = ' <div class="payment-received">
                            <h1> <i class="fa fa-check-circle-o"></i> '.trans('app.payment_thank_you').'</h1>
                            <p>'.trans('app.payment_receive_successfully').'</p>
                            <a href="'.route('home').'" class="btn btn-filled">'.trans('app.home').'</a>
                        </div>';
        return $html;
    }
    
    public function paymentSuccess(Request $request, $transaction_id = null){
        if ($transaction_id){
            $payment = Payment::whereLocalTransactionId($transaction_id)->whereStatus('initial')->first();
            if ($payment){
                $payment->status = 'pending';
                $payment->save();
            }
        }

        $title = trans('app.payment_success');
        return view('public.checkout.success', compact('title'));
    }

    public function paymentBankTransferReceive(Request $request){
        $rules = [
            'bank_swift_code'   => 'required',
            'account_number'    => 'required',
            'branch_name'       => 'required',
            'branch_address'    => 'required',
            'account_name'      => 'required',
        ];
        $this->validate($request, $rules);

        //get Cart Item
        if ( ! session('cart')){
            $title = trans('app.checkout');
            return view('public.checkout.empty', compact('title'));
        }
        //Find the campaign
        $cart = session('cart');

        $amount = 0;
        if(session('cart.cart_type') == 'reward'){
            $reward = Reward::find(session('cart.reward_id'));
            $amount = $reward->amount;
            $campaign = Campaign::find($reward->campaign_id);
        }elseif (session('cart.cart_type') == 'donation'){
            $campaign = Campaign::find(session('cart.campaign_id'));
            $amount = $cart['amount'];
        }
        $currency = get_option('currency_sign');
        $user_id = null;
        if (Auth::check()){
            $user_id = Auth::user()->id;
        }
        //Create payment in database


        $transaction_id = 'tran_'.time().str_random(6);
        // get unique recharge transaction id
        while( ( Payment::whereLocalTransactionId($transaction_id)->count() ) > 0) {
            $transaction_id = 'reid'.time().str_random(5);
        }
        $transaction_id = strtoupper($transaction_id);

        $payments_data = [
            'name' => session('cart.full_name'),
            'email' => session('cart.email'),

            'user_id'               => $user_id,
            'campaign_id'           => $campaign->id,
            'reward_id'             => session('cart.reward_id'),

            'amount'                => $amount,
            'payment_method'        => 'bank_transfer',
            'status'                => 'pending',
            'currency'              => $currency,
            'local_transaction_id'  => $transaction_id,

            'contributor_name_display'  => session('cart.contributor_name_display'),

            'bank_swift_code'   => $request->bank_swift_code,
            'account_number'    => $request->account_number,
            'branch_name'       => $request->branch_name,
            'branch_address'    => $request->branch_address,
            'account_name'      => $request->account_name,
            'iban'              => $request->iban,
        ];
        //Create payment and clear it from session
        $created_payment = Payment::create($payments_data);
        $request->session()->forget('cart');

        return ['success'=>1, 'msg'=> trans('app.payment_received_msg'), 'response' => $this->payment_success_html()];

    }


}
