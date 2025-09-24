<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ProcessPdfController extends Controller
{
    public function processPdf(Request $request)
    {
        try {
            $filename = $request->input('filename');
            
            // Validate filename
            if (!$filename || !str_contains($filename, '.pdf')) {
                return response()->json(['success' => false, 'error' => 'Invalid filename']);
            }
            
            // Build the full path
            $filePath = "storage/pdf_client_test/{$filename}";
            
            // Check if file exists
            if (!file_exists($filePath)) {
                return response()->json(['success' => false, 'error' => 'File not found: ' . $filePath]);
            }
            
            // Execute the process_pdf function
            if (function_exists('process_pdf')) {
                $result = process_pdf($filePath);
                return response()->json(['success' => true, 'result' => $result]);
            } else {
                return response()->json(['success' => false, 'error' => 'process_pdf function not found']);
            }
            
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }
}
