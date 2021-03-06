<?php

namespace App\Http\Controllers\Incomes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Income\Customer as Request;
use App\Models\Auth\User;
use App\Models\Income\Customer;
use App\Models\Income\Invoice;
use App\Models\Income\Revenue;
use App\Models\Setting\Currency;
use App\Utilities\ImportFile;
use Date;
use Illuminate\Http\Request as FRequest;
use Illuminate\Pagination\Paginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class Customers extends Controller
{

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index()
    {
        $customers = Customer::collect();

        return view('incomes.customers.index', compact('customers', 'emails'));
    }

    /**
     * Show the form for viewing the specified resource.
     *
     * @param  Customer  $customer
     *
     * @return Response
     */
    public function show(Customer $customer)
    {
        $amounts = [
            'paid' => 0,
            'open' => 0,
            'overdue' => 0,
        ];

        $counts = [
            'invoices' => 0,
            'revenues' => 0,
        ];

        // Handle invoices
        $invoices = Invoice::with(['status', 'payments'])->where('customer_id', $customer->id)->get();

        $counts['invoices'] = $invoices->count();

        $invoice_payments = [];

        $today = Date::today()->toDateString();

        foreach ($invoices as $item) {
            $payments = 0;

            foreach ($item->payments as $payment) {
                $payment->category       = new \stdClass();
                $payment->category->id   = 0;
                $payment->category->name = trans_choice('general.invoices', 2);

                $invoice_payments[] = $payment;

                $amount = $payment->getConvertedAmount();

                $amounts['paid'] += $amount;

                $payments += $amount;
            }

            if ($item->invoice_status_code == 'paid') {
                continue;
            }

            // Check if it's open or overdue invoice
            if ($item->due_at > $today) {
                $amounts['open'] += $item->getConvertedAmount() - $payments;
            } else {
                $amounts['overdue'] += $item->getConvertedAmount() - $payments;
            }
        }

        // Handle revenues
        $revenues = Revenue::with(['account', 'category'])->where('customer_id', $customer->id)->get();

        $counts['revenues'] = $revenues->count();

        // Prepare data
        $items = collect($revenues)->each(function ($item) use (&$amounts) {
            $amounts['paid'] += $item->getConvertedAmount();
        });

        $limit = request('limit', setting('general.list_limit', '25'));
        $transactions = $this->paginate($items->merge($invoice_payments)->sortByDesc('paid_at'), $limit);

        return view('incomes.customers.show', compact('customer', 'counts', 'amounts', 'transactions'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */
    public function create()
    {
        $currencies = Currency::enabled()->pluck('name', 'code');

        return view('incomes.customers.create', compact('currencies'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  Request  $request
     *
     * @return Response
     */
    public function store(Request $request)
    {
        if (empty($request->input('create_user'))) {
            if (empty($request['email'])) {
                $request['email'] = '';
            }

            Customer::create($request->all());
        } else {
            // Check if user exist
            $user = User::where('email', $request['email'])->first();
            if (!empty($user)) {
                $message = trans('messages.error.customer', ['name' => $user->name]);

                flash($message)->error();

                return redirect()->back()->withInput($request->except('create_user'))->withErrors(
                    ['email' => trans('customers.error.email')]
                );
            }

            // Create user first
            $data = $request->all();
            $data['locale'] = setting('general.default_locale', 'en-GB');

            $user = User::create($data);
            $user->roles()->attach(['3']);
            $user->companies()->attach([session('company_id')]);

            // Finally create customer
            $request['user_id'] = $user->id;

            Customer::create($request->all());
        }

        $message = trans('messages.success.added', ['type' => trans_choice('general.customers', 1)]);

        flash($message)->success();

        return redirect('incomes/customers');
    }

    /**
     * Duplicate the specified resource.
     *
     * @param  Customer  $customer
     *
     * @return Response
     */
    public function duplicate(Customer $customer)
    {
        $clone = $customer->duplicate();

        $message = trans('messages.success.duplicated', ['type' => trans_choice('general.customers', 1)]);

        flash($message)->success();

        return redirect('incomes/customers/' . $clone->id . '/edit');
    }

    /**
     * Import the specified resource.
     *
     * @param  ImportFile  $import
     *
     * @return Response
     */
    public function import(ImportFile $import)
    {
        $rows = $import->all();

        foreach ($rows as $row) {
            $data = $row->toArray();

            if (empty($data['email'])) {
                $data['email'] = '';
            }

            $data['company_id'] = session('company_id');

            Customer::create($data);
        }

        $message = trans('messages.success.imported', ['type' => trans_choice('general.customers', 2)]);

        flash($message)->success();

        return redirect('incomes/customers');
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  Customer  $customer
     *
     * @return Response
     */
    public function edit(Customer $customer)
    {
        $currencies = Currency::enabled()->pluck('name', 'code');

        return view('incomes.customers.edit', compact('customer', 'currencies'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  Customer  $customer
     * @param  Request  $request
     *
     * @return Response
     */
    public function update(Customer $customer, Request $request)
    {
        if (empty($request->input('create_user'))) {
            if (empty($request['email'])) {
                $request['email'] = '';
            }

            $customer->update($request->all());
        } else {
            // Check if user exist
            $user = User::where('email', $request['email'])->first();
            if (!empty($user)) {
                $message = trans('messages.error.customer', ['name' => $user->name]);

                flash($message)->error();

                return redirect()->back()->withInput($request->except('create_user'))->withErrors(
                    ['email' => trans('customers.error.email')]
                );
            }

            // Create user first
            $user = User::create($request->all());
            $user->roles()->attach(['3']);
            $user->companies()->attach([session('company_id')]);

            $request['user_id'] = $user->id;

            $customer->update($request->all());
        }

        $message = trans('messages.success.updated', ['type' => trans_choice('general.customers', 1)]);

        flash($message)->success();

        return redirect('incomes/customers');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  Customer  $customer
     *
     * @return Response
     */
    public function destroy(Customer $customer)
    {
        $relationships = $this->countRelationships($customer, [
            'invoices' => 'invoices',
            'revenues' => 'revenues',
        ]);

        if (empty($relationships)) {
            $customer->delete();

            $message = trans('messages.success.deleted', ['type' => trans_choice('general.customers', 1)]);

            flash($message)->success();
        } else {
            $message = trans('messages.warning.deleted', ['name' => $customer->name, 'text' => implode(', ', $relationships)]);

            flash($message)->warning();
        }

        return redirect('incomes/customers');
    }

    public function currency()
    {
        $customer_id = request('customer_id');

        $customer = Customer::find($customer_id);

        return response()->json($customer);
    }

    public function customer(Request $request)
    {
        if (empty($request['email'])) {
            $request['email'] = '';
        }

        $customer = Customer::create($request->all());

        return response()->json($customer);
    }

    public function field(FRequest $request)
    {
        $html = '';

        if ($request['fields']) {
            foreach ($request['fields'] as $field) {
                switch ($field) {
                    case 'password':
                        $html .= \Form::passwordGroup('password', trans('auth.password.current'), 'key', [], null, 'col-md-6 password');
                        break;
                    case 'password_confirmation':
                        $html .= \Form::passwordGroup('password_confirmation', trans('auth.password.current_confirm'), 'key', [], null, 'col-md-6 password');
                        break;
                }
            }
        }

        $json = [
            'html' => $html
        ];

        return response()->json($json);
    }

    /**
     * Generate a pagination collection.
     *
     * @param array|Collection      $items
     * @param int   $perPage
     * @param int   $page
     * @param array $options
     *
     * @return LengthAwarePaginator
     */
    public function paginate($items, $perPage = 15, $page = null, $options = [])
    {
        $page = $page ?: (Paginator::resolveCurrentPage() ?: 1);

        $items = $items instanceof Collection ? $items : Collection::make($items);

        return new LengthAwarePaginator($items->forPage($page, $perPage), $items->count(), $perPage, $page, $options);
    }
}
