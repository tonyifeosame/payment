<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Category;
use App\Models\Subcategory;
use App\Models\School;

class CategoryController extends Controller
{
    public function index()
    {
        $categories = Category::all();
        return view('categories.index', compact('categories'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255'
        ]);

        Category::create([
            'name' => $request->name
        ]);

        return redirect('/categories')->with('success', 'Category created successfully!');
    }

    public function destroy(Category $category)
    {
        $category->delete();
        return redirect()->route('categories.index')->with('success', 'Category deleted successfully.');
    }

    public function destroySubcategory(Subcategory $subcategory)
    {
        $subcategory->delete();
        return redirect()->route('subcategories.index')->with('success', 'Subcategory deleted successfully.');
    }

    /**
     * Tenant-aware listing for a given school.
     */
    public function indexSchool(School $school)
    {
        $categories = Category::where('school_id', $school->id)->get();
        return view('categories.index', compact('categories', 'school'));
    }

    /**
     * Tenant-aware creation for a given school.
     */
    public function storeSchool(Request $request, School $school)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        Category::create([
            'name' => $request->name,
            'school_id' => $school->id,
        ]);

        return redirect()->route('school.categories.index', ['school' => $school->slug])
                         ->with('success', 'Category created successfully!');
    }
}
