<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Shared upload endpoint used by the attachment demos.
 */
class FileUploadDemoController extends Controller
{
    // ── Shared upload endpoint (demos §1 & §2) ────────────────────────────────

    /**
     * Validate, store the physical file, and persist an Attachment record.
     *
     * Route:  POST /upload
     * Name:   upload.store
     *
     * Accepted fields:
     *   file    — the uploaded file (required)
     *   label   — optional human label
     *   post_id — optional; links the attachment to an existing post
     */
    public function store(Request $request, string $locale): RedirectResponse
    {
        $request->validate([
            'label' => ['nullable', 'string', 'max:255'],
            'post_id' => ['nullable', 'integer', 'exists:posts,id'],
            'file' => [
                'required',
                'file',
                'max:51200', // 50 MB in kilobytes
                'mimes:jpg,jpeg,png,gif,webp,svg,mp4,mov,avi,pdf,doc,docx,xls,xlsx,csv,txt',
            ],
        ]);

        $uploaded = $request->file('file');

        // Determine storage sub-directory:
        //   with post  → posts/{id}/attachments/
        //   standalone → uploads/
        $postId = $request->input('post_id');
        $directory = $postId ? "posts/{$postId}/attachments" : 'uploads';

        // Store file → returns e.g. "uploads/AbCdEf123.jpg"
        $path = $uploaded->store($directory, 'public');

        // ── Persist to database ───────────────────────────────────────────────
        Attachment::create([
            'post_id' => $postId,                          // nullable
            'path' => $path,
            'original_name' => $uploaded->getClientOriginalName(),
            'mime_type' => $uploaded->getMimeType(),
            'size' => $uploaded->getSize(),
            'label' => $request->input('label'),
        ]);

        return redirect()->back()->with('success', 'File uploaded successfully.');
    }
}
