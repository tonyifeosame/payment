<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Subcategory;
use App\Models\Category;

class SubcategoryController extends Controller
{
    public function index()
    {
        $subcategories = Subcategory::with('category')->get();
        $categories = Category::all();
        return view('subcategories.index', compact('subcategories', 'categories'));
    }

    public function create()
    {
        $categories = Category::all();
        return view('subcategories.create', compact('categories'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'category_id' => 'required|exists:categories,id',
            'name'        => 'required|string|max:255',
            'price'       => 'nullable|numeric',
        ]);

        Subcategory::create($request->all());

        return redirect()->route('subcategories.index')
                         ->with('success', 'Subcategory created successfully.');
    }

    public function destroyCategory(Category $category)
    {
        $category->delete();
        return redirect()->route('categories.index')->with('success', 'Category deleted successfully.');
    }

    public function destroy(Subcategory $subcategory)
    {
        $subcategory->delete();
        return redirect()->route('subcategories.index')->with('success', 'Subcategory deleted successfully.');
    }
}
