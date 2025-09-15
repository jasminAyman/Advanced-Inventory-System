<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\ProductCategory;
use App\Models\Product;
use App\Models\Customer;
use App\Models\WareHouse;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;

class SaleReturnController extends Controller
{
    public function AllSalesReturn(){
        $allData = SaleReturn::orderBy('id', 'desc')->get();
        return view('admin.backend.return-sale.all_sale_return', compact('allData'));
    }
    //End Method

    public function AddSalesReturn(){
        $customers = Customer::all();
        $warehouses = WareHouse::all();
        return view('admin.backend.return-sale.add_sale_return', compact('customers', 'warehouses'));
    }
    //End Method

    public function StoreSalesReturn(Request $request){

        $request->validate([
            'date' => "required|date",
            'status' => "required",
        ]);

        try {

            DB::beginTransaction();

            $grandTotal = 0;

            $salesReturn = SaleReturn::create([
                'date' => $request->date,
                'warehouse_id' => $request->warehouse_id,
                'customer_id' => $request->customer_id,
                'discount' => $request->discount ?? 0,
                'shipping' => $request->shipping ?? 0,
                'status' => $request->status,
                'note' => $request->note,
                'grand_total' => 0,
                'paid_amount' => $request->paid_amount,
                'due_amount' => $request->due_amount,
            ]);

            //Store Purchase Items & Update Stock
            foreach($request->products as $productData){
                $product = Product::findOrfail($productData['id']);
                $netUnitCost = $productData['net_unit_cost'] ?? $product->price;

                if ($netUnitCost === null) {
                    throw new \Exception("Net Unit Cost is missing for product id" . $productData['id']);
                }

                $subTotal = ($netUnitCost * $productData['quantity']) - ($productData['discount'] ?? 0);
                $grandTotal += $subTotal;

                SaleReturnItem::create([
                    'sale_return_id' => $salesReturn->id,
                    'product_id' => $productData['id'],
                    'net_unit_cost' => $netUnitCost,
                    'stock' => $product->product_quantity + $productData['quantity'],
                    'quantity' => $productData['quantity'],
                    'discount' => $productData['discount'],
                    'subtotal' => $subTotal,
                ]);

                // update product quantity in product table with the same number of quantity in purchaseItem table
                $product->increment('product_quantity', $productData['quantity']);
            }

            $salesReturn->update(['grand_total' => $grandTotal + $request->shipping - $request->discount]);

            DB::commit();

            $notification = array(
                'message' => 'Sales Return Stored Successfully',
                'alert-type' => 'success'
            );

            return redirect()->route('all.return.sale')->with($notification);
        }
        catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    //End Method

    public function EditSalesReturn($id){
        $editData = SaleReturn::with('saleReturnItems.product')->findOrfail($id);
        $warehouses = WareHouse::all();
        $customers = Customer::all();

        return view('admin.backend.return-sale.edit_sale_return', compact('customers', 'warehouses', 'editData'));
    }
    //End Method

    public function UpdateSalesReturn(Request $request, $id){
        $request->validate([
            'date' => "required|date",
            'status' => "required",
        ]);

        $sales = SaleReturn::findOrfail($id);
        $sales->update([
            'date' => $request->date,
            'warehouse_id' => $request->warehouse_id,
            'customer_id' => $request->customer_id,
            'discount' => $request->discount ?? 0,
            'shipping' => $request->shipping ?? 0,
            'status' => $request->status,
            'note' => $request->note,
            'grand_total' => $request->grand_total,
            'paid_amount' => $request->paid_amount,
            'due_amount' => $request->due_amount,
            'full_amount' => $request->full_amount,
        ]);

        //Delete old sale items
        SaleReturnItem::where('sale_return_id', $sales->id)->delete();

        //Loop for new products and insert new purchase items
        foreach($request->products as $product_id => $product){
            SaleReturnItem::create([
                'sale_return_id' => $sales->id,
                'product_id' => $product_id,
                'net_unit_cost' => $product['net_unit_cost'],
                'stock' => $product['stock'],
                'quantity' => $product['quantity'],
                'discount' => $product['discount'] ?? 0,
                'subtotal' => $product['subtotal'],
            ]);

            //Update product stock with incrementing new quantity
            $productModel = Product::find($product_id);
            if($productModel){
                $productModel->product_quantity += $product['quantity'];
                $productModel->save();
            }
        }

        $notification = array(
            'message' => 'Sale Return Updated Successfully',
            'alert-type' => 'success'
        );

        return redirect()->route('all.return.sale')->with($notification);

    }
    //End Method

    public function DeleteSalesReturn($id){

         try {
            DB::beginTransaction();
            $sales = SaleReturn::findOrFail($id);
            $salesItems = SaleReturnItem::where('sale_return_id', $id)->get();

            foreach($salesItems as $item){
                $product = Product::find($item->product_id);
                if($product){
                    $product->decrement('product_quantity', $item->quantity);
                }
            }
            SaleReturnItem::where('sale_return_id', $id)->delete();
            $sales->delete();
            DB::commit();

            $notification = array(
                'message' => 'Sale Return Deleted Successfully',
                'alert-type' => 'success'
            );

            return redirect()->route('all.return.sale')->with($notification);
        }
        catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    //End Method

    public function DetailsSalesReturn($id){
        $sale = SaleReturn::with(['customer', 'saleReturnItems.product'])->find($id);
        return view('admin.backend.return-sale.sale_return_details', compact('sale'));
    }
    //End Method

    public function InvoiceSalesReturn($id){
        $sale = SaleReturn::with(['customer', 'warehouse', 'saleReturnItems.product'])->find($id);

        $pdf = Pdf::loadView('admin.backend.return-sale.invoice_pdf', compact('sale'));
        return $pdf->download('SaleReturn_'.$id.'.pdf');
    }
    //End Method

}
