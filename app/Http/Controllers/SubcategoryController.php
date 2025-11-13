<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\School;
use App\Models\Subcategory;
use Illuminate\Http\Request;

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
            'name' => 'required|string|max:255',
            'price' => 'nullable|numeric',
        ]);

        Subcategory::create($request->all());

        return redirect()->route('subcategories.index')
            ->with('success', 'Subcategory created successfully.');
    }

    public function edit(Subcategory $subcategory)
    {
        $categories = Category::all();

        return view('subcategories.edit', compact('subcategory', 'categories'));
    }

    public function update(Request $request, Subcategory $subcategory)
    {
        $request->validate([
            'category_id' => 'required|exists:categories,id',
            'name' => 'required|string|max:255',
            'price' => 'nullable|numeric',
        ]);

        $subcategory->update($request->all());

        return redirect()->route('subcategories.index')
            ->with('success', 'Subcategory updated successfully.');
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
            'name' => 'required|string|max:255',
            'price' => 'nullable|numeric',
        ]);

        // Ensure the chosen category belongs to this school
        $category = Category::where('school_id', $school->id)->findOrFail($data['category_id']);

        Subcategory::create([
            'category_id' => $category->id,
            'name' => $data['name'],
            'price' => $data['price'] ?? null,
            'school_id' => $school->id,
        ]);

        return redirect()->route('school.subcategories.index', ['school' => $school->slug])
            ->with('success', 'Subcategory created successfully.');
    }

    public function editSchool(School $school, Subcategory $subcategory)
    {
        $categories = Category::where('school_id', $school->id)->get();

        return view('subcategories.edit', compact('school', 'subcategory', 'categories'));
    }

    public function updateSchool(Request $request, School $school, Subcategory $subcategory)
    {
        $data = $request->validate([
            'category_id' => 'required|exists:categories,id',
            'name' => 'required|string|max:255',
            'price' => 'nullable|numeric',
        ]);

        // Ensure the chosen category belongs to this school
        $category = Category::where('school_id', $school->id)->findOrFail($data['category_id']);

        // Ensure the subcategory belongs to the school
        if ($subcategory->school_id !== $school->id) {
            abort(403);
        }

        $subcategory->update([
            'category_id' => $category->id,
            'name' => $data['name'],
            'price' => $data['price'] ?? null,
        ]);

        return redirect()->route('school.subcategories.index', ['school' => $school->slug])
            ->with('success', 'Subcategory updated successfully.');
    }

    public function destroySchool(School $school, Subcategory $subcategory)
    {
        // Ensure the subcategory belongs to the school
        if ($subcategory->school_id !== $school->id) {
            abort(403);
        }

        $subcategory->delete();

        return redirect()->route('school.subcategories.index', ['school' => $school->slug])
            ->with('success', 'Subcategory deleted successfully.');
    }
}
