<?php
/**
 * Created by PhpStorm.
 * User: miftah.fathudin
 * Date: 11/14/2016
 * Time: 12:29 PM
 */

namespace App\Services\Implementation;


use App\Model\Customer;
use App\Model\Item;
use App\Model\Lookup;
use App\Model\Product;
use App\Model\ProductUnit;
use App\Model\SalesOrder;
use App\Services\SalesOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class SalesOrderServiceImpl implements SalesOrderService
{

    /**
     * Save(create) a newly sales order. The saved(created) sales order will be returned.
     * Multiple sales orders can be created at once and all of them will be saved to user session as an array by default.
     * This method will only save the sales order at sales order array in user session with given index and will remove it from that array.
     *
     * @param Request $request request which contains values from create form to create the sales order.
     * @param int $index index of sales order in sales order array in user session to be saved.
     * @return SalesOrder
     */
    public function createSO(Request $request, $index)
    {
        if ($request->input("customer_type.$index") == 'CUSTOMERTYPE.R') {
            $customer_id = empty($request->input("customer_id.$index")) ? 0 : $request->input("customer_id.$index");
            $walk_in_cust = '';
            $walk_in_cust_detail = '';
        } else {
            $customer_id = 0;
            $walk_in_cust = $request->input("walk_in_customer.$index");
            $walk_in_cust_detail = $request->input("walk_in_customer_details.$index");
        }

        $params = [
            'customer_type' => $request->input("customer_type.$index"),
            'customer_id' => $customer_id,
            'walk_in_cust' => $walk_in_cust,
            'walk_in_cust_detail' => $walk_in_cust_detail,
            'code' => $request->input("so_code.$index"),
            'so_type' => $request->input("sales_type.$index"),
            'so_created' => date('Y-m-d H:i:s', strtotime($request->input("so_created.$index"))),
            'shipping_date' => date('Y-m-d H:i:s', strtotime($request->input("shipping_date.$index"))),
            'status' => Lookup::whereCode('SOSTATUS.WD')->first()->code,
            'vendor_trucking_id' => empty($request->input("vendor_trucking_id.$index")) ? 0 : $request->input("vendor_trucking_id.$index"),
            'warehouse_id' => $request->input("warehouse_id.$index"),
            'remarks' => $request->input("remarks.$index"),
            'store_id' => Auth::user()->store_id
        ];

        $so = SalesOrder::create($params);

        for ($j = 0; $j < count($request->input("so_$index" . "_product_id")); $j++) {
            $item = new Item();
            $item->product_id = $request->input("so_$index" . "_product_id.$j");
            $item->stock_id = empty($request->input("so_$index" . "_stock_id.$j")) ? 0 : $request->input("so_$index" . "_stock_id.$j");
            $item->store_id = Auth::user()->store_id;
            $item->selected_unit_id = $request->input("so_$index" . "_selected_unit_id.$j");
            $item->base_unit_id = $request->input("so_$index" . "_base_unit_id.$j");
            $item->conversion_value = ProductUnit::where([
                'product_id' => $item->product_id,
                'unit_id' => $item->selected_unit_id
            ])->first()->conversion_value;
            $item->quantity = $request->input("so_$index" . "_quantity.$j");
            $item->price = floatval(str_replace(',', '', $request->input("so_$index" . "_price.$j")));
            $item->to_base_quantity = $item->quantity * $item->conversion_value;

            $so->items()->save($item);
        }

        $userSOs = session('userSOs');
        $userSOs->splice($index, 1);
        session(['userSOs' => $userSOs]);

        return $so;
    }

    /**
     * Cancel a single sales order.
     * Multiple sales orders can be created at once and all of them will be saved to user session as an array by default.
     * This method will remove the sales order in sales order array in user session with given index.
     *
     * @param int $index index of the sales order in sales orders array in user session to be cancelled.
     *
     * @return void
     */
    public function cancelSO($index)
    {
        $userSOs = session('userSOs');
        $userSOs->splice($index, 1);
        session(['userSOs' => $userSOs]);
    }

    /**
     * Revise(modify) a sales order. If the sales order is still waiting for arrival, it's warehouse,
     * vendor trucking, shipping date and items can be changed. But, if it is already waiting for payment,
     * only it's items price can be changed. The revised(modified) sales order will be returned.
     *
     * @param Request $request request which contains values from revise form to revise the sales order.
     * @param int $id the id of sales order to be revised.
     * @return SalesOrder
     */
    public function reviseSO(Request $request, $id)
    {
        // Get current SO
        $currentSo = SalesOrder::with('items')->find($id);

        // Get ID of current SO's items
        $soItemsId = $currentSo->items->map(function ($item) {
            return $item->id;
        })->all();

        // Get the id of removed items
        $soItemsToBeDeleted = array_diff($soItemsId, $request->input('item_id'));

        // Remove the item that removed on the revise page
        Item::destroy($soItemsToBeDeleted);

        $currentSo->warehouse_id = $request->input('warehouse_id');
        $currentSo->shipping_date = date('Y-m-d H:i:s', strtotime($request->input('shipping_date')));
        $currentSo->remarks = $request->input('remarks');
        $currentSo->vendor_trucking_id = empty($request->input('vendor_trucking_id')) ? 0 : $request->input('vendor_trucking_id');

        for ($i = 0; $i < count($request->input('item_id')); $i++) {
            $item = Item::findOrNew($request->input("item_id.$i"));
            $item->product_id = $request->input("product_id.$i");
            $item->stock_id = empty($request->input("stock_id.$i")) ? 0 : $request->input("stock_id.$i");
            $item->store_id = Auth::user()->store_id;
            $item->selected_unit_id = $request->input("selected_unit_id.$i");
            $item->base_unit_id = $request->input("base_unit_id.$i");
            $item->conversion_value = ProductUnit::where([
                'product_id' => $item->product_id,
                'unit_id' => $item->selected_unit_id
            ])->first()->conversion_value;
            $item->quantity = $request->input("quantity.$i");
            $item->price = floatval(str_replace(',', '', $request->input("price.$i")));
            $item->to_base_quantity = $item->quantity * $item->conversion_value;

            $currentSo->items()->save($item);
        }

        $currentSo->save();

        return $currentSo;
    }

    /**
     * Reject a sales order. Only sales orders with status waiting for arrival can be rejected.
     *
     * @param Request $request request which contains values for sales order rejection.
     * @param $id int the id of sales order to be rejected.
     * @return void
     */
    public function rejectSO(Request $request, $id)
    {
        $so = SalesOrder::find($id);
        $so->status = 'SOSTATUS.RJT';
        $so->save();
    }

    /**
     * Store sales orders sent from the request to user session as a collection.
     * @param Request $request request which contains values for sales orders
     * @return Collection
     */
    public function storeToSession(Request $request){
        $SOs = [];

        for($i = 0; $i < count($request->input('so_code')); $i++){
            $items = [];
            for ($j = 0; $j < count($request->input("so_$i"."_product_id")); $j++) {
                $items[] = [
                    'quantity' => $request->input("so_$i"."_quantity.$j"),
                    'selected_unit' => [
                        'conversion_value' => ProductUnit::where([
                            'product_id' => $request->input("so_$i"."_product_id.$j"),
                            'unit_id' => $request->input("so_$i"."_selected_unit_id.$j")
                        ])->first()->conversion_value,
                        'unit' => [
                            'id' => $request->input("so_$i"."_selected_unit_id.$j")
                        ]
                    ],
                    'product' => Product::with('productUnits.unit')->find($request->input("so_$i"."_product_id.$j")),
                    'stock_id' => empty($request->input("so_$i"."stock_id.$i")) ? 0 : $request->input("so_$i"."_stock_id.$j"),
                    'base_unit' => [
                        'unit' => [
                            'id' => $request->input("so_$i"."_base_unit_id.$j")
                        ]
                    ],
                    'price' => floatval(str_replace(',', '', $request->input("so_$i"."_price.$j")))
                ];
            }

            $SOs[] = [
                'customer_type' => [
                    'code' => $request->input("customer_type.$i")
                ],
                'customer' => Customer::find($request->input("customer_id.$i")),
                'walk_in_cust' => $request->input("walk_in_customer.$i"),
                'walk_in_cust_details' => $request->input("walk_in_customer_details.$i"),
                'so_code' => $request->input("so_code.$i"),
                'sales_type' => [
                    'code' => $request->input("sales_type.$i")
                ],
                'so_created' => $request->input("so_created.$i"),
                'shipping_date' => $request->input("shipping_date.$i"),
                'warehouse' => [
                    'id' => $request->input("warehouse_id.$i")
                ],
                'vendorTrucking' => [
                    'id' => empty($request->input("vendor_trucking_id.$i")) ? 0 : $request->input("vendor_trucking_id.$i")
                ],
                'remarks' => $request->input("remarks.$i"),
                'items' => $items
            ];
        }

        session(['userSOs' => collect($SOs)]);
    }
}