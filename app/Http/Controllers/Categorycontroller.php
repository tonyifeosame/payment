<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\School;
use Illuminate\Http\Request;

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
            'name' => 'required|string|max:255',
        ]);

        Category::create([
            'name' => $request->name,
        ]);

        return redirect('/categories')->with('success', 'Category created successfully!');
    }

    public function edit(Category $category)
    {
        return view('categories.edit', compact('category'));
    }

    public function update(Request $request, Category $category)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $category->update([
            'name' => $request->name,
        ]);

        return redirect()->route('categories.index')->with('success', 'Category updated successfully!');
    }

    public function destroy(Category $category)
    {
        $category->delete();

        return redirect()->route('categories.index')->with('success', 'Category deleted successfully.');
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

    public function editSchool(School $school, Category $category)
    {
        return view('categories.edit', compact('school', 'category'));
    }

    public function updateSchool(Request $request, School $school, Category $category)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        // Ensure the category belongs to the school
        if ($category->school_id !== $school->id) {
            abort(403);
        }

        $category->update([
            'name' => $request->name,
        ]);

        return redirect()->route('school.categories.index', ['school' => $school->slug])
            ->with('success', 'Category updated successfully!');
    }

    public function destroySchool(School $school, Category $category)
    {
        // Ensure the category belongs to the school
        if ($category->school_id !== $school->id) {
            abort(403);
        }

        $category->delete();

        return redirect()->route('school.categories.index', ['school' => $school->slug])
            ->with('success', 'Category deleted successfully.');
    }
}
