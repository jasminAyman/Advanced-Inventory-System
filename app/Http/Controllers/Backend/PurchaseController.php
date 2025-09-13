<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\ProductCategory;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\WareHouse;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;

class PurchaseController extends Controller
{
    public function AllPurchase(){

        $allData = Purchase::orderBy('id', 'desc')->get();
        return view('admin.backend.purchase.all_purchase', compact('allData'));
    }
    //End Method

    public function AddPurchase(){
        $suppliers = Supplier::all();
        $warehouses = WareHouse::all();
        return view('admin.backend.purchase.add_purchase', compact('suppliers', 'warehouses'));
    }
    //End Method

    public function PurchaseProductSearch(Request $request){
        $query = $request->input('query');
        $warehouse_id = $request->input('warehouse_id');

        $products = Product::where(function($q) use ($query){
            $q->where('name', 'like', "%{$query}%")
            ->orwhere('code', 'like', "%{$query}%");
        })
        ->when($warehouse_id, function($q) use ($warehouse_id){
            $q->where('warehouse_id', $warehouse_id);
        })
        ->select('id', 'name', 'code', 'price', 'product_quantity')->limit(10)->get();

        return response()->json($products);
    }
    //End Method

    public function StorePurchase(Request $request){

        $request->validate([
            'date' => "required|date",
            'status' => "required",
            'supplier_id' => "required"
        ]);

        try {

            DB::beginTransaction();

            $grandTotal = 0;

            $purchase = Purchase::create([
                'date' => $request->date,
                'warehouse_id' => $request->warehouse_id,
                'supplier_id' => $request->supplier_id,
                'discount' => $request->discount ?? 0,
                'shipping' => $request->shipping ?? 0,
                'status' => $request->status,
                'note' => $request->note,
                'grand_total' => 0,
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

                PurchaseItem::create([
                    'purchase_id' => $purchase->id,
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

            $purchase->update(['grand_total' => $grandTotal + $request->shipping - $request->discount]);

            DB::commit();

            $notification = array(
                'message' => 'Purchase Stored Successfully',
                'alert-type' => 'success'
            );

            return redirect()->route('all.purchase')->with($notification);
        }
        catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    //End Method

    public function EditPurchase($id){
        $editData = Purchase::with('purchaseItems.product')->findOrfail($id);
        $warehouses = WareHouse::all();
        $suppliers = Supplier::all();

        return view('admin.backend.purchase.edit_purchase', compact('suppliers', 'warehouses', 'editData'));
    }
    //End Method

    public function UpdatePurchase(Request $request, $id){
        $request->validate([
            'date' => "required|date",
            'status' => "required",
        ]);

        DB::beginTransaction();

        try {

            $purchase = Purchase::findOrfail($id);
            $purchase->update([
                'date' => $request->date,
                'warehouse_id' => $request->warehouse_id,
                'supplier_id' => $request->supplier_id,
                'discount' => $request->discount ?? 0,
                'shipping' => $request->shipping ?? 0,
                'status' => $request->status,
                'note' => $request->note,
                'grand_total' => $request->grand_total,
            ]);

            ///Get old Purchase Items
            $purchaseOldItems = PurchaseItem::where('purchase_id', $purchase->id)->get();

            ///Loop for old purchase items and decrement product quantity
            foreach($purchaseOldItems as $oldItem){
                $product = Product::find($oldItem->product_id);
                if($product){
                    $product->decrement('product_quantity', $oldItem->quantity);
                    //Decrement old quantity
                }
            }

            //Delete old purchase items
            PurchaseItem::where('purchase_id', $purchase->id)->delete();

            //Loop for new products and insert new purchase items
            foreach($request->products as $product_id => $productData){
                purchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'product_id' => $product_id,
                    'net_unit_cost' => $productData['net_unit_cost'],
                    'stock' => $productData['stock'],
                    'quantity' => $productData['quantity'],
                    'discount' => $productData['discount'] ?? 0,
                    'subtotal' => $productData['subtotal'],
                ]);

                //Update product stock with incrementing new quantity
                $product = Product::find($product_id);
                if($product){
                    $product->increment('product_quantity', $productData['quantity']);
                    //increment new quantity
                }
            }

            DB::commit();

            $notification = array(
                'message' => 'Purchase Updated Successfully',
                'alert-type' => 'success'
            );

            return redirect()->route('all.purchase')->with($notification);
        }
        catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    //End Method

    public function DeletePurchase($id){

        try {
            DB::beginTransaction();
            $purchase = Purchase::findOrFail($id);
            $purchaseItems = PurchaseItem::where('purchase_id', $id)->get();

            foreach($purchaseItems as $item){
                $product = Product::find($item->product_id);
                if($product){
                    $product->decrement('product_quantity', $item->quantity);
                }
            }
            PurchaseItem::where('purchase_id', $id)->delete();
            $purchase->delete();
            DB::commit();

            $notification = array(
                'message' => 'Purchase Deleted Successfully',
                'alert-type' => 'success'
            );

            return redirect()->route('all.purchase')->with($notification);
        }
        catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    //End Method

    public function DetailsPurchase($id){
        $purchase = Purchase::with(['supplier', 'purchaseItems.product'])->find($id);
        return view('admin.backend.purchase.purchase_details', compact('purchase'));
    }
    //End Method

    public function InvoicePurchase($id){
        $purchase = Purchase::with(['supplier', 'warehouse', 'purchaseItems.product'])->find($id);

        $pdf = Pdf::loadView('admin.backend.purchase.invoice_pdf', compact('purchase'));
        return $pdf->download('purchase_'.$id.'.pdf');
    }
    //End Method
}
