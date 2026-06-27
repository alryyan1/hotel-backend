<?php

namespace App\Http\Controllers;

use App\Models\ContestParticipant;
use Illuminate\Http\Request;

class ContestController extends Controller
{
    /**
     * جلب قائمة المشتركين مع إمكانية البحث
     */
    public function index(Request $request)
    {
        $query = ContestParticipant::query();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('phone_number', 'like', "%{$search}%")
                  ->orWhere('address', 'like', "%{$search}%");
            });
        }

        $participants = $query->latest()->get();

        return response()->json($participants);
    }

    /**
     * السحب العشوائي المطور (تشتيت فيزيائي + عشوائية تشفيرية آمنة)
     */
    public function draw()
    {
        // 1. جلب الـ IDs فقط للمشتركين المؤهلين (الذين لم يفوزوا بعد)
        $eligibleIds = ContestParticipant::where('has_won', false)->pluck('id')->toArray();

        // 2. التحقق من وجود مشتركين متاحين للسحب
        if (empty($eligibleIds)) {
            return response()->json(['message' => 'لا يوجد مشتركون متاحون للسحب حالياً'], 404);
        }

        // 3. الخلط الفيزيائي العنيف للمصفوفة (تدمير الترتيب التصاعدي تماماً)
        shuffle($eligibleIds);

        // 4. اختيار عنصر عشوائي باستخدام random_int الآمنة تشفيرياً والمشتتة تماماً للأطراف والمنتصف
        $randomIndex = random_int(0, count($eligibleIds) - 1);
        $randomId = $eligibleIds[$randomIndex];

        // 5. جلب بيانات الفائز الفعلي بناءً على الـ ID المختار
        $winner = ContestParticipant::find($randomId);

        // 6. تحديث حالة الفائز في قاعدة البيانات ليصبح فائزاً لمنع تكراره
        $winner->update(['has_won' => true]);

        // 7. إرجاع بيانات كائن الفائز كاملاً متوافقاً مع الفرونت إند
        return response()->json($winner);
    }

    /**
     * تسجيل مشترك جديد في المسابقة
     */
    public function store(Request $request)
    {
        // 1. التحقق من البيانات القادمة من الـ React
        $validated = $request->validate([
            'full_name'    => 'required|string|max:255',
            'phone_number' => 'required|string|max:50',
            'address'      => 'required|string|max:255',
        ]);

        // 2. حفظ البيانات في الجدول الجديد
        ContestParticipant::create([
            'full_name'    => $validated['full_name'],
            'phone_number' => $validated['phone_number'],
            'address'      => $validated['address'],
        ]);

        // 3. إرجاع رد نجاح للـ React بجانب حالة 201 (تم الإنشاء)
        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل مشاركتك في السحب بنجاح! نتمنى لك الفوز.'
        ], 201);
    }
}