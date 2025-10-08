<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Subcategory;
use App\Models\Category;
use App\Models\School;

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

    /**
     * Tenant-aware listing for a given school.
     */
    public function indexSchool(School $school)
    {
        $subcategories = Subcategory::with('category')
            ->where('school_id', $school->id)
            ->get();
        $categories = Category::where('school_id', $school->id)->get();
        return view('subcategories.index', compact('subcategories', 'categories', 'school'));
    }

    /**
     * Tenant-aware create view.
     */
    public function createSchool(School $school)
    {
        $categories = Category::where('school_id', $school->id)->get();
        return view('subcategories.create', compact('categories', 'school'));
    }

    /**
     * Tenant-aware store for a given school.
     */
    public function storeSchool(Request $request, School $school)
    {
        $data = $request->validate([
            'category_id' => 'required|exists:categories,id',
            'name'        => 'required|string|max:255',
            'price'       => 'nullable|numeric',
        ]);

        // Ensure the chosen category belongs to this school
        $category = Category::where('school_id', $school->id)->findOrFail($data['category_id']);

        Subcategory::create([
            'category_id' => $category->id,
            'name'        => $data['name'],
            'price'       => $data['price'] ?? null,
            'school_id'   => $school->id,
        ]);

        return redirect()->route('school.subcategories.index', ['school' => $school->slug])
                         ->with('success', 'Subcategory created successfully.');
    }
}
