<?php

namespace App\Http\Controllers;

use App\Http\Controllers\SuperAdmin\CustomController;
use App\Models\Appointment;
use App\Models\Category;
use App\Models\Doctor;
use App\Models\Expertise;
use App\Models\Hospital;
use App\Models\HospitalGallery;
use App\Models\Lab;
use App\Models\LabGallery;
use App\Models\Medicine;
use App\Models\MedicineCategory;
use App\Models\Offer;
use App\Models\Pathology;
use App\Models\PathologyCategory;
use App\Models\ProviderType;
use App\Models\Pharmacy;
use App\Models\Radiology;
use App\Models\RadiologyCategory;
use App\Models\Report;
use App\Models\Treatments;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class MultiDeleteController extends Controller
{
    private function authorizeAny(array $abilities): void
    {
        abort_if(Gate::none($abilities), Response::HTTP_FORBIDDEN, '403 Forbidden');
    }

    private function isSuperAdmin(): bool
    {
        return auth()->user()->hasRole('super admin');
    }

    private function ownLabId(): ?int
    {
        return $this->isSuperAdmin() ? null : Lab::where('user_id', auth()->id())->value('id');
    }

    public function category_all_delete(Request $request)
    {
        $this->authorizeAny(['category_delete']);
        $ids = explode(',', $request->ids);
        foreach ($ids as $id) {
            $category = Category::find($id);
            $category->delete();
        }

        return response(['success' => true]);
    }

    public function appointment_all_delete(Request $request)
    {
        abort_unless($this->isSuperAdmin(), Response::HTTP_FORBIDDEN, '403 Forbidden');
        $ids = explode(',', $request->ids);
        foreach ($ids as $id) {
            $appointment = Appointment::find($id);
            $appointment->delete();
        }

        return response(['success' => true]);
    }

    public function treatment_all_delete(Request $request)
    {
        $this->authorizeAny(['treatment_delete']);
        $ids = explode(',', $request->ids);
        foreach ($ids as $id) {
            $treat = Treatments::find($id);
            (new CustomController)->deleteFile($treat->image);
            $treat->delete();
        }

        return response(['success' => true]);
    }

    public function expertise_all_delete(Request $request)
    {
        $this->authorizeAny(['expertise_delete']);
        $ids = explode(',', $request->ids);
        foreach ($ids as $id) {
            Expertise::find($id)->delete();
        }

        return response(['success' => true]);
    }

    public function medicineCategory_all_delete(Request $request)
    {
        $this->authorizeAny(['medicine_category_delete']);
        $ids = explode(',', $request->ids);
        foreach ($ids as $id) {
            $medicineCategory = MedicineCategory::find($id);
            $medicineCategory->delete();
        }

        return response(['success' => true]);
    }

    public function hospital_all_delete(Request $request)
    {
        $this->authorizeAny(['hospital_delete']);
        $ids = explode(',', $request->ids);
        foreach ($ids as $id) {
            $id = Hospital::find($id);
            $hospital_galleries = HospitalGallery::where('hospital_id', $id)->get();
            foreach ($hospital_galleries as $hospital_gallery) {
                (new CustomController)->deleteFile($hospital_gallery->image);
                $hospital_gallery->delete();
            }
            (new CustomController)->deleteFile($id->image);
            $id->delete();
        }

        return response(['success' => true]);
    }

    public function provider_type_all_delete(Request $request)
    {
        $this->authorizeAny(['provider_type_delete']);
        $ids = explode(',', $request->ids);
        foreach ($ids as $id) {
            $type = ProviderType::find($id);
            if ($type && ! $type->doctors()->exists()) {
                $type->delete();
            }
        }

        return response(['success' => true]);
    }

    public function provider_all_delete(Request $request)
    {
        $this->authorizeAny(['provider_delete']);
        $ids = explode(',', $request->ids);
        foreach ($ids as $id) {
            $provider = Doctor::where('user_role', 'provider')->find($id);
            if ($provider) {
                $user = User::find($provider->user_id);
                if ($user) {
                    $user->removeRole('provider');
                    $user->delete();
                }
                (new CustomController)->deleteFile($provider->image);
                $provider->delete();
            }
        }

        return response(['success' => true]);
    }

    public function doctor_all_delete(Request $request)
    {
        $this->authorizeAny(['doctor_delete']);
        $ids = explode(',', $request->ids);
        foreach ($ids as $id) {
            $offers = Offer::all();
            foreach ($offers as $value) {
                $doctor_id = explode(',', $value['doctor_id']);
                if (($key = array_search($id, $doctor_id)) !== false) {
                    return response(['success' => false, 'data' => 'This doctor connected with Offer, Please remove doctor from offer first.']);
                }
            }
            $id = Doctor::find($id);
            $user = User::find($id->user_id);
            $user->delete();
            (new CustomController)->deleteFile($id->image);
            $id->delete();
        }

        return response(['success' => true]);
    }

    public function pharmacy_all_delete(Request $request)
    {
        $this->authorizeAny(['pharmacy_delete']);
        $ids = explode(',', $request->ids);
        foreach ($ids as $id) {
            $pharmacy = Pharmacy::find($id);
            (new CustomController)->deleteFile($pharmacy->image);
            $user = User::find($pharmacy->user_id);
            $user->delete();
            $pharmacy->delete();
        }

        return response(['success' => true]);
    }

    public function patient_all_delete(Request $request)
    {
        $this->authorizeAny(['patient_delete']);
        $query = User::doesntHave('roles')->whereIn('id', explode(',', $request->ids));
        if (auth()->user()->hasRole('doctor')) {
            $query->where('doctor_id', Doctor::where('user_id', auth()->id())->value('id'));
        }
        foreach ($query->get() as $user) {
            $user->delete();
        }

        return response(['success' => true]);
    }

    public function offer_all_delete(Request $request)
    {
        $this->authorizeAny(['offer_delete']);
        $ids = explode(',', $request->ids);
        foreach ($ids as $id) {
            $offer = Offer::find($id);
            (new CustomController)->deleteFile($offer->image);
            $offer->delete();
        }

        return response(['success' => true]);
    }

    public function medicine_all_delete(Request $request)
    {
        $this->authorizeAny(['admin_medicine_delete', 'medicine_delete']);
        $query = Medicine::whereIn('id', explode(',', $request->ids));
        if (! auth()->user()->can('admin_medicine_delete')) {
            $query->where('pharmacy_id', Pharmacy::where('user_id', auth()->id())->value('id'));
        }
        foreach ($query->get() as $medicine) {
            (new CustomController)->deleteFile($medicine->image);
            $medicine->delete();
        }

        return response(['success' => true]);
    }

    public function pathology_all_delete(Request $request)
    {
        $this->authorizeAny(['pathology_delete']);
        $query = Pathology::whereIn('id', explode(',', $request->ids));
        if (! $this->isSuperAdmin()) {
            $query->where('lab_id', $this->ownLabId());
        }
        foreach ($query->get() as $pathology) {
            $reports = Report::where('lab_id', $pathology->lab_id)->get();
            foreach ($reports as $report) {
                $rs = explode(',', $report->pathology_id);
                if (in_array($pathology->id, $rs)) {
                    $report->delete();
                }
            }
            $pathology->delete();
        }

        return response(['success' => true]);
    }

    public function radiology_all_delete(Request $request)
    {
        $this->authorizeAny(['radiology_delete']);
        $query = Radiology::whereIn('id', explode(',', $request->ids));
        if (! $this->isSuperAdmin()) {
            $query->where('lab_id', $this->ownLabId());
        }
        foreach ($query->get() as $radiology) {
            $reports = Report::where('lab_id', $radiology->lab_id)->get();
            foreach ($reports as $report) {
                $rs = explode(',', $report->radiology_id);
                if (in_array($radiology->id, $rs)) {
                    $report->delete();
                }
            }
            $radiology->delete();
        }

        return response(['success' => true]);
    }

    public function lab_all_delete(Request $request)
    {
        $this->authorizeAny(['lab_delete']);
        $ids = explode(',', $request->ids);
        foreach ($ids as $id) {
            $lab = Lab::find($id);
            $user = User::find($lab->user_id);
            $user->removeRole('laboratory');
            $user->delete();
            (new CustomController)->deleteFile($lab->image);
            $galleries = LabGallery::where('lab_id', $lab->id)->get();
            foreach ($galleries as $gallery) {
                (new CustomController)->deleteFile($gallery->image);
                $gallery->delete();
            }
            $lab->delete();
        }

        return response(['success' => true]);
    }

    public function pathology_cat_all_delete(Request $request)
    {
        $this->authorizeAny(['pathology_category_delete']);
        $ids = explode(',', $request->ids);
        foreach ($ids as $id) {
            PathologyCategory::find($id)->delete();
        }

        return response(['success' => true]);
    }

    public function radiology_cat_all_delete(Request $request)
    {
        $this->authorizeAny(['radiology_category_delete']);
        $ids = explode(',', $request->ids);
        foreach ($ids as $id) {
            RadiologyCategory::find($id)->delete();
        }

        return response(['success' => true]);
    }
}
