<?php

return [

    // Days after a document is soft-deleted before
    // `documents:purge-deleted-files` will remove its physical files. The
    // documents/document_signatures/document_release_stamps DB rows are
    // never deleted by that command — only file bytes are reclaimed.
    'file_retention_days' => (int) env('DOCUMENT_FILE_RETENTION_DAYS', 90),

];
