<?php

namespace App\Http\Controllers\Admin\Order;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HelperController;
use App\Models\Account\Account;
use App\Models\Account\AccountLog;
use App\Models\Order\Order;
use App\Models\Order\OrderDeliveryInfo;
use App\Models\Order\OrderDetails;
use App\Models\Order\OrderPayment;
use App\Models\Product\Product;
use App\Models\Settings\AppSettingTitle;
use App\Models\User\Address;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class AdminOrderController extends Controller
{
    public $message = "";
    public $order_id = null;
    public $type = "create";

    public function all_products(Request $request)
    {
        $paginate = (int) 10;
        $orderBy = "id";
        $orderByType = "ASC";


        $status = 1;
        if (request()->has('status')) {
            $status = request()->status;
        }


        $query = Product::where('status', $status)->orderBy($orderBy, $orderByType);

        if (request()->has('search_key')) {
            $key = request()->search_key;
            $query->where(function ($q) use ($key) {
                return $q->where('id', $key)
                    ->orWhere('product_name', $key)
                    ->orWhere('sales_price', $key)
                    ->orWhere('product_name', 'LIKE', '%' . $key . '%')
                    ->orWhere('sales_price', 'LIKE', '%' . $key . '%');
            });
        }
        $query->withSum('stocks', 'qty');
        $query->withSum('sales', 'qty');
        $users = $query->paginate($paginate);
        return response()->json($users);
    }

    public function store_order()
    {
        $data = request()->all();
        $validator = Validator::make($data, [
            "carts" => ["required", "array", "min:1"],
            "customer_id" => ["required"]
        ], [
            "carts.required" => ["there is no product into cart list."]
        ]);
        if ($validator->fails()) {
            return response()->json([
                'err_message' => 'validation error',
                'data' => $validator->errors(),
            ], 422);
        }

        $carts = request()->carts;
        $products = [];
        $sub_total_cost = 0;
        $total_cost = 0;
        $shipping_cost = 0;
        $total_discount = 0;
        $message_products = "";

        $get_product_details = $this->get_product_details($carts, request()->all());
        $products = $get_product_details['products'];
        $sub_total_cost = $get_product_details['sub_total_cost'];
        $total_discount = $get_product_details['total_discount'];
        $shipping_cost = $get_product_details['shipping_cost'];
        $total_cost = $get_product_details['total_cost'];
        $message_products = $get_product_details['message_products'];

        $order = $this->save_order([
            "products" => $products,
            "request" => request()->except('carts'),
            "sub_total_cost" => $sub_total_cost,
            "total_cost" => $total_cost,
            "total_discount" => $total_discount,
            "shipping_cost" => $shipping_cost,
            "coupon_info" => "",
        ]);

        $this->make_message([
            "message_products" => $message_products,
            "sub_total_cost" => $sub_total_cost,
            "shipping_cost" => $shipping_cost,
            "coupon_discount" => 0,
            "total_cost" => $total_cost,
            "name" => auth()->user()->first_name . ' ' . auth()->user()->last_name,
            "mobile_number" => auth()->user()->mobile_number,
            "address" => "",
            "invoice_id" => $order->invoice_id,
            "type" => "create order",
        ]);

        return response()->json([
            "message" => "Order Completed Successfully",
            "order" => $order->invoice_id,
        ], 200);
    }

    public function update_order()
    {
        $this->type = "update";
        if (request()->has('order_id') && request()->order_id) {
            $this->order_id = request()->order_id;
        }
        $data = request()->all();
        $validator = Validator::make($data, [
            "order_id" => ['required'],
            "carts" => ["required", "array", "min:1"],
        ], [
            "carts.required" => ["there is no product into cart list."]
        ]);
        if ($validator->fails()) {
            return response()->json([
                'err_message' => 'validation error',
                'data' => $validator->errors(),
            ], 422);
        }

        $carts = request()->carts;
        $products = [];
        $sub_total_cost = 0;
        $total_cost = 0;
        $shipping_cost = 0;
        $total_discount = 0;
        $message_products = "";

        $get_product_details = $this->get_product_details($carts, request()->all());
        $products = $get_product_details['products'];
        $sub_total_cost = $get_product_details['sub_total_cost'];
        $total_discount = $get_product_details['total_discount'];
        $shipping_cost = $get_product_details['shipping_cost'];
        $total_cost = $get_product_details['total_cost'];
        $message_products = $get_product_details['message_products'];

        $order = $this->save_order([
            "products" => $products,
            "request" => request()->except('carts'),
            "sub_total_cost" => $sub_total_cost,
            "total_cost" => $total_cost,
            "total_discount" => $total_discount,
            "shipping_cost" => $shipping_cost,
            "coupon_info" => "",
        ]);

        $this->make_message([
            "message_products" => $message_products,
            "sub_total_cost" => $sub_total_cost,
            "shipping_cost" => $shipping_cost,
            "coupon_discount" => 0,
            "total_cost" => $total_cost,
            "name" => auth()->user()->first_name . ' ' . auth()->user()->last_name,
            "mobile_number" => auth()->user()->mobile_number,
            "address" => "",
            "invoice_id" => $order->invoice_id,
            "type" => "update_order",
        ]);

        return response()->json([
            "message" => "Order Completed Successfully",
            "order" => $order->invoice_id,
        ], 200);
    }

    /**
     * ```php
     * get_product_details($carts=[], $request):[
     *     "total_cost" => $total_cost,
     *     "products" => $products,
     *     "message_products" => $message_products,
     *     "sub_total_cost" => $sub_total_cost,
     *     "sub_total_cost" => $sub_total_cost,
     *   ]
     * ```
     */
    public function get_product_details($carts = [], $request)
    {
        // dd($request);
        $request = (object) $request;
        $delivery_cost = HelperController::delivery_cost();
        $products = [];
        $sub_total_cost = 0;
        $total_cost = 0;
        $shipping_cost = $request->shipping_charge ? $request->shipping_charge : $delivery_cost->out_dhaka_home_delivery_cost;
        $total_discount = $request->discount ? $request->discount : 0;
        $message_products = "";

        foreach ($carts as $key => $item) {
            $item = (object) $item;
            if (isset($item->product_id)) {
                $product = Product::find($item->product_id);
            } else {
                $product = Product::find($item->id);
            }
            $si = $key + 1;
            $product->qty = $item->qty;
            $product->discount_percent = $item->discount_percent;
            $product->discount_price = $item->current_price;
            $products[] = $product;
            $main_price = $product->sales_price;

            // if(isset($product->discount_info->discount_percent)){
            //     $discount_percent = $product->discount_info->discount_percent;
            //     $price = $product->discount_info->discount_amount ? $product->discount_info->discount_price : $product->sales_price;
            // }

            $discount_percent = $item->discount_percent;
            $price = $item->current_price; // calculated including discount;

            $total = $item->qty * $main_price;
            $sub_total_cost += $total;
            // $total_discount += $product->discount_info->discount_amount;
            $total_discount += ($item->qty * ($item->sales_price - $item->current_price));
            $bn_price = enToBn("৳ $price x $item->qty	= ৳ $total \n\t\t\t (৳ $main_price - $discount_percent%)");
            $message_products .= "$si. $item->product_name - \n\t\t\t $bn_price \n";
        }

        $total_cost = $sub_total_cost + $shipping_cost - $total_discount;
        // dd($total_discount, $sub_total_cost, $shipping_cost, $total_cost);

        return [
            "products" => $products,
            "sub_total_cost" => $sub_total_cost,
            "total_discount" => $total_discount,
            "shipping_cost" => $shipping_cost,
            "total_cost" => $total_cost,
            "message_products" => $message_products,
        ];
    }

    public function save_order($data = [])
    {
        $products = $data["products"];
        $request = $data["request"];
        $sub_total_cost = $data["sub_total_cost"];
        $total_cost = $data["total_cost"];
        $total_discount = $data["total_discount"];
        $shipping_cost = $data["shipping_cost"];
        $coupon_info = $data["coupon_info"];
        $request = (object) $request;
        $auth_user = auth()->check() ? auth()->user() : null;
        $address = $this->save_address($request);
        $variant_price = 0;
        $invoice_prefix = AppSettingTitle::getValue("invoice_prefix");
        $user_id = $request->customer_id ?? null;

        $order_data = [
            // 'user_id' => $auth_user ? $auth_user->id : null, // user id
            // "customer_id" => null, //customer id
            "address_id" => $address->id, // user address id, customer
            "invoice_id" => $invoice_prefix . "-" . Carbon::now()->format("Ymd"),
            "invoice_date" => Carbon::now()->toDateTimeString(),
            "order_type" => "invoice", // Quotation, Pos order, Ecomerce order
            "order_status" => "pending",
            // "order_coupon_id" => $coupon_info["order_coupon_id"],
            "order_coupon_id" => null,

            "sub_total" => $sub_total_cost,
            "discount" => $total_discount,
            "coupon_discount" => 0,
            "delivery_charge" => $shipping_cost,
            "variant_price" => $variant_price, // extra charge for product variants
            "total_price" => $total_cost + $variant_price,

            "payment_status" => "pending", // pending, partially paid, paid
            // "delivery_method" => $request->shipping_method,
            "delivery_method" => "courier_out_dhaka", // courier_in_dhaka, courier_out_dhaka, pickup
        ];

        $order = new Order();
        if ($this->type == "update") {
            $order = Order::find($this->order_id);
            unset($order_data['invoice_id']);
            unset($order_data['invoice_date']);
            $order->fill($order_data);
            OrderDetails::where('order_id', $this->order_id)->delete();
        } else {
            $order = Order::create($order_data);
            $order->invoice_id .= $order->id;
            $order->user_id = $user_id;
        }

        $order->save();

        foreach ($products as $product) {
            $sales_price = $product->discount_info->discount_price ? $product->discount_info->discount_price : $product->sales_price;
            OrderDetails::create([
                "order_id" => $order->id,
                "product_id" => $product->id,
                "product_name" => $product->product_name,
                "product_code" => $product->sku,
                "product_price" => $product->sales_price,
                // "discount_percent" => $product->discount_info->discount_amount,
                // "discount_price" => $product->discount_info->discount_amount,
                // "sales_price" => $product->discount_info->discount_price,
                "discount_percent" => $product->discount_percent,
                "discount_price" => $product->discount_price,
                "sales_price" => $sales_price,
                "qty" => $product->qty,
                "user_id" => $order->user_id,
            ]);
        }

        // $this->save_delivery_info($order, $request, $shipping_cost, $address);
        // $this->save_order_payments($order, $request);

        return $order;
    }

    public function save_delivery_info($order, $request, $shipping_cost, $address)
    {
        $auth_user = auth()->check() ? auth()->user() : null;
        if (isset($request->customer_id)) {
            $user_id = $request->customer_id;
        } else {
            $user_id = $auth_user ? $auth_user->id : null;
        }
        OrderDeliveryInfo::create([
            "order_id" => $order->id,
            "user_id" => $user_id,
            "customer_id" => null,
            "delivery_method" => isset($request->shipping_method) ? $request->shipping_method : '',
            "delivery_cost" => $shipping_cost,
            "courier_name" => "",
            "address_id" => $address->id,
            "location_id" => $address->id, // shipping id
        ]);
    }

    public function save_address($request)
    {
        $auth_user = auth()->check() ? auth()->user() : null;
        $address = null;
        $request = (object) $request;
        if ($auth_user) {
            $address = Address::where('table_name', 'users')->where('table_id', $auth_user->id)->orderBy('id', 'DESC')->first();
            if (!$address) {
                $address = new Address();
            }
        }

        $address->fill([
            "table_name" => $auth_user ? "users" : "guest",
            "table_id" => $auth_user ? $auth_user->id : null,
            "address_type" => "shipping",
            "first_name" => $request->first_name ?? '',
            "last_name" => $request->last_name ?? '',
            "mobile_number" => $request->mobile_number ?? $auth_user->mobile_number,
            "email" => $request->email ?? $auth_user->email,
            "address" => $request->address ?? '',
            "city" => $request->city ?? '',
            "state" => $request->state ?? '',
            "zip_code" => $request->zip_code ?? '',
            "zone" => $request->zone ?? '',
            "country" => $request->country ?? '',
            "comment" => $request->comment ?? '',
        ])->save();

        return $address;
    }

    /**
     * ```php
     make_message([
        "message_products" => $message_products,
        "sub_total_cost" => $sub_total_cost,
        "shipping_cost" => $shipping_cost,
        "coupon_discount" => 0,
        "total_cost" => $total_cost,
        "name" => auth()->user()->first_name.' '.auth()->user()->last_name,
        "mobile_number" => auth()->user()->mobile_number,
        "address" => "",
        "invoice_id" => $order->invoice_id,
        "type" => "update_order",
     ]);
     *```
     */
    public function make_message($data)
    {
        $message_products = $data["message_products"];
        $sub_total_cost = $data["sub_total_cost"];
        $shipping_cost = $data["shipping_cost"];
        $coupon_discount = $data["coupon_discount"];
        $total_cost = $data["total_cost"];
        $name = $data["name"];
        $mobile_number = $data["mobile_number"];
        $address = $data["address"];
        $invoice_id = $data["invoice_id"];
        $type = $data["type"];

        $now = Carbon::now()->format("d M, Y h:i a");
        $invoice_url = url("/invoice/$invoice_id");
        $this->message .= "আসসালামু আলাইকুম ওয়ারহমাতুল্লাহ। \n";

        if ($type == "update_order") {
            $this->message .= "একটি অর্ডার আপডেট হয়েছে \n";
        } else {
            $this->message .= "নতুন অর্ডার এসেছে \n";
        }

        $this->message .= "অর্ডার এর সময়:  $now \n";
        $this->message .= "অর্ডার এর বিবরণ \n";

        $this->message .= "------------------- \n";
        $this->message .= $message_products;

        $this->message .= "------------------- \n";
        $this->message .= enToBn("সাবটোটাল - ৳ $sub_total_cost \n");
        $this->message .= enToBn("ডেলিভারি চার্জ - ৳ $shipping_cost \n");
        if ($coupon_discount) {
            $this->message .= enToBn("কুপন ছাড় - ৳ -$coupon_discount \n");
        }
        $this->message .= enToBn("সর্বমোট মূল্য - ৳ $total_cost \n");

        $this->message .= "------------------- \n";
        $this->message .= "অর্ডারকারীর বিবরণ \n";
        $this->message .= "নাম : $name \n";
        $this->message .= "মোবাইল নাম্বার : $mobile_number \n";
        $this->message .= "ঠিকানা : $address \n";
        $this->message .= "------------------- \n";
        $this->message .= "বিস্তারিত : $invoice_url";
        $this->send_telegram($this->message);
    }

    public function send_telegram($message)
    {
        $bot_token = env('BOT_TOKEN');
        $method = "sendMessage";

        $parameters = [
            'chat_id' => 812239513,
            'text' => $message,
        ];

        $url = "https://api.telegram.org/bot$bot_token/$method";

        $response = Http::get($url . '?chat_id=' . $parameters['chat_id'] . '&text=' . $parameters['text']);
        return $response->json();
    }

    public function receive_due()
    {
        // dd(request()->all());
        $validator = Validator::make(request()->all(), [
            "order_id" => ["required"],
            "account_id" => ["required"],
            "payment_method" => ["required"],
            "trx_id" => ["required"],
            "amount" => ["required"],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'err_message' => 'validation error',
                'data' => $validator->errors(),
            ], 422);
        }

        $payment_method_info = json_decode(request()->payment_method);
        $payment_account = Account::find($payment_method_info->account_id);
        $order_payment = OrderPayment::create([
            "order_id" => request()->order_id,
            "user_id" => auth()->user()->id,
            "payment_method" => $payment_account->name,
            "number" => $payment_method_info->value,
            "trx_id" => request()->trx_id,
            "amount" => request()->amount,
            "date" => Carbon::now()->toDateString(),
            "approved" => 1,
            "account_id" => request()->account_id,
            "account_number_id" => $payment_method_info->id,
        ]);

        $order = Order::find(request()->order_id);
        $total_paid = OrderPayment::where('order_id', $order->id)->sum('amount');
        $order->total_paid = $total_paid;
        if ($total_paid < $order->total_price) {
            $order->payment_status =  'partially paid';
        }
        if ($total_paid == 0) {
            $order->payment_status =  'due';
        }
        if ($total_paid >= $order->total_price) {
            $order->payment_status =  'paid';
        }
        $order->save();

        if (!$order->sales_id) {
            $payment_controller = new PaymentRequestController();
            $payment_controller->set_sales_id($order);
        }

        $account_log = AccountLog::class;
        $log = AccountLog::create([
            'date' => Carbon::now()->toDateTimeString(),
            "name" => $order_payment->user->first_name . " " . $order_payment->user->last_name,
            'amount' => $order_payment->amount,
            'category_id' => 1, // ponno theke ay
            'account_id' => $order_payment->account_id,
            'account_number_id' => $order_payment->account_number_id,
            'trx_id' => $order_payment->trx_id,
            'receipt_no' => request()->receipt_no,
            'is_income' => 1,
            'description' => 'admin received and inserted client payment',
        ]);

        $order_payment->account_logs_id = $log->id;
        $order_payment->save();

        // $this->make_due_pay_message([
        //     "transaction_media" => $payment_method_info->title,
        //     "transaction_id" => request()->trx_id,
        //     "transaction_amount" => enToBn(number_format(request()->amount)),
        //     "total" => enToBn(number_format($order->total_price)),
        //     "paid" => enToBn(number_format($order->total_paid)),
        //     "due" => enToBn(number_format($order->total_price - $order->total_paid)),
        //     "name" => auth()->user()->first_name.' '.auth()->user()->last_name,
        //     "mobile_number" => auth()->user()->mobile_number,
        //     "invoice" => url("/invoice".'/'.$order->invoice_id),
        //     "time" => Carbon::now()->format('d M, Y h:i a'),
        // ]);
        return response()->json([$order, $order_payment]);
    }

    public function make_due_pay_message($data = [])
    {
        $transaction_media = $data["transaction_media"];
        $transaction_id = $data["transaction_id"];
        $transaction_amount = $data["transaction_amount"];
        $total = $data["total"];
        $paid = $data["paid"];
        $due = $data["due"];
        $name = $data["name"];
        $mobile_number = $data["mobile_number"];
        $invoice = $data["invoice"];
        $time = $data["time"];

        $message = "আসসালামু আলাইকুম ওয়ারহমাতুল্লাহ। \n";
        $message .= "আপনার একাউন্ট এ লেনদেন হয়েছে \n";
        $message .= "সময় : $time  \n";

        $message .= "ট্রাকজেকশন এর বিবরণ  \n";
        $message .= "------------------- \n";
        $message .= "ট্রাকজেকশন মাধ্যম : $transaction_media \n";
        $message .= "ট্রাকজেকশন আইডি : $transaction_id \n";
        $message .= "ট্রাকজেকশন পরিমাণ : $transaction_amount \n";
        $message .= "------------------- \n";
        $message .= "Total :   $total \n";
        $message .= "Paid  : - $paid \n";
        $message .= "Due   :   $due \n";
        $message .= "------------------- \n";
        $message .= "অর্ডারকারীর বিবরণ \n";
        $message .= "নাম : $name \n";
        $message .= "মোবাইল নাম্বার : $mobile_number \n";
        // $message .= "ঠিকানা :  \n";
        $message .= "------------------- \n";
        $message .= "বিস্তারিত : $invoice";
        $this->send_telegram($message);
    }

    public function delete_payment()
    {
        $validator = Validator::make(request()->all(), [
            "payment_id" => ["required", "exists:order_payments,id"],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'err_message' => 'validation error',
                'data' => $validator->errors(),
            ], 422);
        }
        $payment = OrderPayment::find(request()->payment_id);
        // if ($payment && $payment->approved) {
        //     return response()->json([
        //         'err_message' => 'validation error',
        //         'data' => ["payment_id" => ["deleting this payment is not permitted."]],
        //     ], 422);
        // }
        if ($payment) {

            $order = Order::find($payment->order_id);
            $order->total_paid = $order->order_payments()->sum('amount') - $payment->amount;
            if ($order->total_paid == $order->total_price) {
                $order->payment_status = 'paid';
            } else if ($order->total_paid > $order->total_price) {
                $order->payment_status = 'partially paid';
            } else {
                $order->payment_status = 'pending';
                $order->backup_sales_id = $order->sales_id;
                $order->sales_id = null;
            }
            $order->save();

            if ($payment->account_logs_id) {
                $log = AccountLog::create([
                    'date' => Carbon::now()->toDateTimeString(),
                    "name" => $payment->user->first_name . " " . $payment->user->last_name,
                    'amount' => - ($payment->amount),
                    'category_id' => 1, // ponno theke ay
                    'account_id' => $payment->account_id,
                    'account_number_id' => $payment->account_number_id,
                    'trx_id' => $payment->trx_id,
                    'receipt_no' => $payment->receipt_no,
                    'is_income' => 1,
                    'description' => 'admin rejected client payment',
                ]);
            }

            $payment->delete();
        }

        return response()->json('success');
    }

    public function approve_payment()
    {
        $validator = Validator::make(request()->all(), [
            "payment_id" => ["required", "exists:order_payments,id"],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'err_message' => 'validation error',
                'data' => $validator->errors(),
            ], 422);
        }
        $payment = OrderPayment::find(request()->payment_id);

        if ($payment) {
            $payment->approved = 1;
            $payment->save();

            $order = Order::find($payment->order_id);
            $order->total_paid = $order->order_payments()->sum('amount');
            if ($order->total_paid == $order->total_price) {
                $order->payment_status = 'paid';
            } else if ($order->total_paid > $order->total_price) {
                $order->payment_status = 'partially paid';
            }
            $order->save();

            $log = AccountLog::create([
                'date' => Carbon::now()->toDateTimeString(),
                "name" => $payment->user->first_name . " " . $payment->user->last_name,
                'amount' => $payment->amount,
                'category_id' => 1, // ponno theke ay
                'account_id' => $payment->account_id,
                'account_number_id' => $payment->account_number_id,
                'trx_id' => $payment->trx_id,
                'receipt_no' => request()->receipt_no,
                'is_income' => 1,
                'description' => 'admin accepted payment',
            ]);

            $payment->account_logs_id = $log->id;
            $payment->save();
        }

        return response()->json('success');
    }

    public function update_order_status()
    {
        $validator = Validator::make(request()->all(), [
            "order_id" => ["required", "exists:orders,id"],
            "status" => ['required'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'err_message' => 'validation error',
                'data' => $validator->errors(),
            ], 422);
        }
        $order = Order::find(request()->order_id);
        $order_status = request()->status;

        $order->order_status = $order_status;
        $order->save();

        return response()->json($order);
        dd($order->toArray(), $order_status);
    }
}
