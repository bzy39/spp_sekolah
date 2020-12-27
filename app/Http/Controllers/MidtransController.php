<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Configuration;
use App\Models\Tunggakan;
use App\Models\Transaction;

use Auth;
use App\Models\Parents;

class MidtransController extends Controller
{
    protected $config;
    protected static $tunggakan;
    private $serverKey = "SB-Mid-server-qI9m7N32aAQ-apqqdxS2r8tM";
    function __construct(Configuration $c, Tunggakan $t)
    {
        self::$tunggakan = $t;
        $this->config = $c;
    }
    public function index()
    {
        $config = $this->config->first();
        if($config === null)
        {
            $config = (object) [
                'id' => null,
                'client_key' => null,
                'secret_key' => null,
                'url' => null,
                'production' => 0
            ];
        }
        return view('admin.midtrans.index', compact('config'));
    }
    public function store(Request $req)
    {
        // $this->config->updateOrCreate([
        //         'client_key' => $req->client_key,
        //         'secret_key' => $req->secret_key,
        //         'url' => $req->url,
        // ]);
        
        if($req->id === null)
        {
          $update = $this->config->create([
                    'client_key' => $req->client_key,
                    'secret_key' => $req->secret_key,
                    'url' => $req->url,
            ]);
            if($update)
            {
                return redirect()->back()->with(['success' => 'berhasil di perbarui']);
            }else{
                // return false;
                return redirect()->back()->with(['error' => 'gagal di perbarui']);

            }
        }else{
            $update = $this->config->find($req->id);
            $update->url = $req->url;
            $update->client_key = $req->client_key;
            $update->secret_key = $req->secret_key;
            if($update->save())
            {
                return redirect()->back()->with(['success' => 'berhasil di perbarui']);
            }else{
                // return false;
                return redirect()->back()->with(['error' => 'gagal di perbarui']);

            }
        }
    }
    public function turn_on(Request $req)
    {
        $on = $this->config->first();
        $on->production = 1;
        if($on->save())
        {
            return true;
        }else{
            return false;
        }
    }
    public function turn_off(Request $req)
    {
        $on = $this->config->first();
        $on->production = 0;
        if($on->save())
        {
            return true;
        }else{
            return false;
        }
    }
    public static function getSnap($array)
    {
        \Midtrans\Config::$serverKey = "SB-Mid-server-qI9m7N32aAQ-apqqdxS2r8tM";
        // Set to Development/Sandbox Environment (default). Set to true for Production Environment (accept real transaction).
        \Midtrans\Config::$isProduction = false;
        // Set sanitization on (default)
        \Midtrans\Config::$isSanitized = true;
        // Set 3DS transaction for credit card to true
        \Midtrans\Config::$is3ds = true;
        $subtotal = 0;
        
        // $data = Tunggakan::whereIn('id', [1, 2])->get();
        
        // $params = array();
        // $user = Parents::find(Auth::id());


        // for($i = 0; $i < count($data); $i++)
        // {
        //     array_push($params, array(
        //         'transaction_details' => array(
        //             'order_id' => $data[$i]->id,
        //             'gross_amount' =>  $data[$i]->total,
        //         ),
                
        //     ));
            
        // }
        // $snapToken = null;
        // foreach($params as $paramss)
        // {
        //     $snapToken = \Midtrans\Snap::getSnapToken($paramss);
        // }
        // return $snapToken;

        $tunggakan = Tunggakan::whereIn('id', $array)->get();
        foreach($tunggakan as $tunggakans)
        {
            $subtotal+= $tunggakans->total;
        }
        $time = time();
        
        $params = array(
            'transaction_details' => array(
                'order_id' => $time,
                'gross_amount' => $subtotal
            )
        );
        $json = json_encode($array);
        $save = Transaction::create([
            'order_id' => $time,
            'tunggakan_id' => $json,
            'snap_token' => \Midtrans\Snap::getSnapToken($params),
            'subtotal' => $subtotal,
            'transaction_details' => json_encode($params)
        ]);
        if($save)
        {
            return $save->snap_token;
        }else{
            return false;
        }
    }
    public function callback(Request $req)
    {
        $notif = file_get_contents('php://input');
        $notif = json_decode($notif, TRUE);
        $transaction = $notif['transaction_status'];
        $fraud = $notif['fraud_status'];
        $orderid = $notif['order_id'];

        // Storage::disk('local')->put('callback.txt', $notif['order_id']);

        $get = $this->transaction->where('order_id', $orderid)->first();

        error_log("Order ID $orderid: "."transaction status = $transaction, fraud staus = $fraud");

        if ($transaction == 'settlement') {
            if ($fraud == 'challenge') {
              $get->status = "challenge";
              $get->save();
            }
            else if ($fraud == 'accept') {
                $get->status = "success";
                $get->payment_type = $notif['payment_type'];
                $tunggakan_id = json_decode($get->tunggakan_id);
                $update_tr = $this->tunggakan->whereIn('id', $tunggakan_id)->update([
                    'status' => 'paid',
                    'updated_at' => now()
                ]);
                $get->save();
            }
        }
        else if ($transaction == 'cancel') {
            if ($fraud == 'challenge') {
                $get->status = "failure";
                $get->save();
            }
            else if ($fraud == 'accept') {
                $get->status = "failure";
                $get->save();
            }
        }
        else if ($transaction == 'deny') {
            $get->status = "failure";
            $get->save();
        }

    }
}
