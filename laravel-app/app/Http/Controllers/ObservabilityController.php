<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class ObservabilityController extends Controller
{
    public function normal(Request $request)
    {
        return response()->json(['message' => 'normal response', 'timestamp' => now()]);
    }

    public function slow(Request $request)
    {
        $hard = (int)$request->query('hard', 0);

        if ($hard === 1) {
            sleep(rand(5, 7));
        } else {
            usleep(700000); // 700ms
        }

        return response()->json(['message' => 'slow response', 'hard' => $hard]);
    }

    public function error(Request $request)
    {
        abort(500, 'Simulated system error');
    }

    public function random(Request $request)
    {
        $choices = [
            fn() => $this->normal($request),
            fn() => $this->slow($request),
            fn() => $this->error($request),
        ];

        return $choices[array_rand($choices)]();
    }

    public function db(Request $request)
    {
        if ((int)$request->query('fail', 0) === 1) {
            // Force query exception by querying non-existing table
            return \DB::table('non_existing_table')->get();
        }

        // Use sqlite or mysql connection via default config
        // ensure a simple table exists via migration `users`.
        $result = \DB::table('users')->limit(1)->get();

        return response()->json(['db' => $result]);
    }

    public function validateInput(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'age' => 'required|integer|between:18,60',
        ]);

        if ($validator->fails()) {
            abort(422, json_encode($validator->errors()->messages()));
        }

        return response()->json(['message' => 'validated']);
    }
}
