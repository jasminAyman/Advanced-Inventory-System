<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ProductCategory;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Brand;
use App\Models\Supplier;
use App\Models\WareHouse;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

class ProductController extends Controller
{
    public function AllCategory(){
        $category = ProductCategory::latest()->get();
        return view('admin.backend.category.all_category', compact('category'));
    }
    //End Method

    public function StoreCategory(Request $request){

        ProductCategory::insert([
            'category_name' => $request->category_name,
            'category_slug' => strtolower(str_replace(' ', '-', $request->category_name)),
        ]);

        $notification = array(
            'message' => 'Product Category Inserted Successfully',
            'alert-type' => 'success'
        );

        return redirect()->back()->with($notification);

    }
    //End Method

    public function EditCategory($id){
        $category = ProductCategory::find($id);
        return response()->json($category);
    }
    //End Method

    public function UpdateCategory(Request $request){

        $category_id = $request->cat_id;

        ProductCategory::find($category_id)->update([
            'category_name' => $request->category_name,
            'category_slug' => strtolower(str_replace(' ', '-', $request->category_name)),
        ]);

        $notification = array(
            'message' => 'Product Category Updated Successfully',
            'alert-type' => 'success'
        );

        return redirect()->back()->with($notification);

    }
    //End Method

    public function DeletCategory($id){

        ProductCategory::find($id)->delete();

        $notification = array(
            'message' => 'Product Category Deleted Successfully',
            'alert-type' => 'success'
        );

        return redirect()->back()->with($notification);
    }
    //End Method

/*...................................................................................... */
////////////All method for products////////////////

    public function AllProduct(){

        $allData = Product::orderBy('id', 'desc')->get();
        return view('admin.backend.product.product_list', compact('allData'));
    }
    //End Method

    public function AddProduct(){
        $categories = ProductCategory::all();
        $brands = Brand::all();
        $suppliers = Supplier::all();
        $warehouses = WareHouse::all();

        return view('admin.backend.product.add_product', compact('categories','brands', 'suppliers', 'warehouses'));
    }
    //End Method

    public function StoreProduct(Request $request){

        $product = Product::create([
            'name' => $request->name,
            'code' => $request->code,
            'category_id' => $request->category_id,
            'brand_id' => $request->brand_id,
            'warehouse_id' => $request->warehouse_id,
            'supplier_id' => $request->supplier_id,
            'price' => $request->price,
            'stock_alert' => $request->stock_alert,
            'note' => $request->note,
            'product_quantity' => $request->product_quantity,
            'status' => $request->status,
            'created_at' => now(),
        ]);

        $product_id = $product->id;

        ///multiple image upload
        if ($request->hasFile('image')) {
            foreach($request->file('image') as $img){
                $manager = new ImageManager(new Driver());
                $name_gen = hexdec(uniqid()).'.'.$img->getClientOriginalExtension();
                $imgs = $manager->read($img);
                $imgs->resize(150,150)->save(public_path('upload/productimg/'.$name_gen));
                $save_url = 'upload/productimg/'.$name_gen;

                ProductImage::create([
                    'product_id' => $product_id,
                    'image' => $save_url
                ]);
            }
        }

        $notification = array(
            'message' => 'Product Inserted Successfully',
            'alert-type' => 'success'
        );

        return redirect()->route('all.product')->with($notification);
    }
    //End Method

    public function EditProduct($id){
        $editData = Product::find($id);
        $categories = ProductCategory::all();
        $brands = Brand::all();
        $suppliers = Supplier::all();
        $warehouses = WareHouse::all();
        $multiimg = ProductImage::where('product_id', $id)->get();

        return view('admin.backend.product.edit_product', compact('categories','brands', 'suppliers', 'warehouses', 'editData', 'multiimg'));
    }
    //End Method

    public function UpdateProduct(Request $request){

        $pro_id = $request->id;
        $product = Product::findOrFail($pro_id);

        $product->name = $request->name;
        $product->code = $request->code;
        $product->category_id = $request->category_id;
        $product->brand_id = $request->brand_id;
        $product->price = $request->price;
        $product->stock_alert = $request->stock_alert;
        $product->note = $request->note;
        $product->warehouse_id = $request->warehouse_id;
        $product->supplier_id = $request->supplier_id;
        $product->product_quantity = $request->product_quantity;
        $product->status = $request->status;
        $product->save();

        if ($request->hasFile('image')) {
            foreach($request->file('image') as $img){
                $manager = new ImageManager(new Driver());
                $name_gen = hexdec(uniqid()).'.'.$img->getClientOriginalExtension();
                $imgs = $manager->read($img);
                $imgs->resize(150,150)->save(public_path('upload/productimg/'.$name_gen));

                $product->images()->create([
                    'image' => 'upload/productimg/'.$name_gen,
                ]);
            }
        }


        //checkbox remove
        if ($request->has('remove_image')) {
            foreach($request->remove_image as $removeImageId){

                $img = ProductImage::find($removeImageId);
                if($img){
                    if(file_exists(public_path($img->image))){
                        unlink(public_path($img->image));
                    }
                    $img->delete();
                }
            }
        }

        $notification = array(
            'message' => 'Product Updated Successfully',
            'alert-type' => 'success'
        );

        return redirect()->route('all.product')->with($notification);

    }
    //End Method

    public function DeleteProduct($id){

        $product = Product::findOrFail($id);

        //delete associated images from upload folder
        $images = ProductImage::where('product_id', $id)->get();
        foreach($images as $img){
            $imagePath = public_path($img->image);
            if(file_exists($imagePath)){
                unlink($imagePath);
            }
        }

        //delete image from record in db
        ProductImage::where('product_id', $id)->delete();
        $product->delete(); //delete product

        $notification = array(
            'message' => 'Product Deleted Successfully',
            'alert-type' => 'success'
        );

        return redirect()->back()->with($notification);

    }
    //End Method

    public function DetailsProduct($id){
        $product = Product::findorFail($id);
        return view('admin.backend.product.details_product', compact('product'));
    }
    //End Method

}
