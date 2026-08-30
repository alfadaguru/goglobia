<?php
// categories.php
@$SECURE or die('Access Denied!');
?>

<div class="container my-4">
    <?php if (isset($_SESSION['message'])): ?>
        <div class="<?= $_SESSION['message']['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-4">
            <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
            <div><?= $_SESSION['message']['text'] ?></div>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <!-- Header Section -->
    <div class="flex justify-between items-center mb-4">
        <h2 class="text-xl font-semibold"><?= T::blog_categories_management ?? 'Blog Categories Management' ?></h2>
        <button onclick="showAddModal()" class="btn flex items-center gap-2">
            <span class="material-symbols-outlined">add</span>
            <?= T::add_category ?? 'Add Category' ?>
        </button>
    </div>

    <?php
    echo crud()
        ->table('blog_categories')
        ->title('')
        ->col('id,cat_name,cat_slug,created_at')
        ->label([
            'id' => T::id ?? 'ID',
            'cat_name' => T::name ?? 'Name',
            'cat_slug' => T::slug ?? 'Slug',
            'status' => T::status ?? 'Status',
            'created_at' => T::created_at ?? 'Created At'
        ])
        ->row([
            'cat_name' => '
                <div class="flex items-center gap-3">
                    <span class="material-symbols-outlined text-gray-500">category</span>
                    <span class="font-medium">{{cat_name}}</span>
                </div>
            ',
            'cat_slug' => '
                <span class="text-sm text-gray-600 bg-gray-100 px-2 py-1 rounded">{{cat_slug}}</span>
            ',
            'created_at' => function($row) {
                return date('M d, Y', strtotime($row['created_at']));
            }
        ])
        ->order('id', 'DESC')
        ->actions([
            'add' => false,
            'view' => false,
            'edit' => false,
            'delete' => false,
            'status' => true,
            'search' => true,
        ])
        ->custom_button([
            'label' => '',
            'icon' => 'edit',
            'url' => 'javascript:void(0)',
            'onclick' => 'showEditModal({id}); return false;',
            'class' => 'text-blue-600 hover:bg-blue-100 px-2 py-1 rounded hover:bg-blue-50',
            'title' => T::edit ?? 'Edit'
        ])
        ->custom_button([
            'label' => '',
            'icon' => 'delete',
            'url' => 'javascript:void(0)',
            'onclick' => 'deleteCategory({id}); return false;',
            'class' => 'text-red-600 hover:bg-red-100 px-2 py-1 rounded hover:bg-red-50',
            'title' => T::delete ?? 'Delete'
        ])
        ->col_width('status', '100px')
        ->render();
    ?>
</div>

<!-- Add/Edit Modal -->
<div id="categoryModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 hidden">
    <div class="bg-white rounded-lg shadow-lg w-full max-w-md mx-4">
        <div class="flex items-center justify-between p-6 border-b">
            <h3 class="text-lg font-semibold" id="modalTitle"><?= T::add_category ?? 'Add Category' ?></h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        
        <form id="categoryForm" method="post" class="p-6">
            <?= CSRF::tokenField() ?>
            <input type="hidden" id="category_id" name="id" value="">
            
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2 required">
                    <?= T::category_name ?? 'Category Name' ?> *
                </label>
                <input type="text" id="cat_name" name="cat_name" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required>
            </div>
            
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    <?= T::slug ?? 'Slug' ?>
                </label>
                <input type="text" id="cat_slug" name="cat_slug" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="<?= T::auto_generate ?? 'Auto-generate from name' ?>">
                <p class="text-xs text-gray-500 mt-1"><?= T::slug_hint ?? 'Leave empty to auto-generate from category name' ?></p>
            </div>
            
            <div class="mb-6">
                <div class="flex items-center">
                    <input type="checkbox" id="status" name="status" value="1" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded" checked>
                    <label for="status" class="ml-2 block text-sm text-gray-900">
                        <?= T::active_status ?? 'Active Status' ?>
                    </label>
                </div>
            </div>
            
            <div class="flex justify-end gap-3 pt-4 border-t">
                <button type="button" onclick="closeModal()" class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-md">
                    <?= T::cancel ?? 'Cancel' ?>
                </button>
                <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-md" id="submitBtn">
                    <?= T::save ?? 'Save' ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// CSRF Token for AJAX requests
const csrfToken = document.querySelector('input[name="csrf_token"]').value;

// Modal Functions
function showAddModal() {
    resetForm();
    document.getElementById('modalTitle').textContent = '<?= T::add_category ?? "Add Category" ?>';
    document.getElementById('submitBtn').textContent = '<?= T::save ?? "Save" ?>';
    document.getElementById('categoryModal').classList.remove('hidden');
}

function showEditModal(id) {
    fetch(`<?= root ?>admin/blogs/categories/get/${id}`)
        .then(response => response.json())
        .then(data => {
            if(data.success) {
                document.getElementById('category_id').value = data.data.id;
                document.getElementById('cat_name').value = data.data.cat_name;
                document.getElementById('cat_slug').value = data.data.cat_slug;
                document.getElementById('status').checked = data.data.status == 1;
                
                document.getElementById('modalTitle').textContent = '<?= T::edit_category ?? "Edit Category" ?>';
                document.getElementById('submitBtn').textContent = '<?= T::update ?? "Update" ?>';
                document.getElementById('categoryModal').classList.remove('hidden');
            } else {
                alert(data.message || 'Error loading category');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading category');
        });
}

function closeModal() {
    document.getElementById('categoryModal').classList.add('hidden');
}

function resetForm() {
    document.getElementById('categoryForm').reset();
    document.getElementById('category_id').value = '';
    document.getElementById('status').checked = true;
}

// Delete Category
function deleteCategory(id) {
    if(confirm('<?= T::confirm_delete_category ?? "Are you sure you want to delete this category?" ?>')) {
        fetch(`<?= root ?>admin/blogs/categories/delete/${id}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            }
        })
        .then(response => response.json())
        .then(data => {
            if(data.success) {
                location.reload();
            } else {
                alert(data.message || 'Error deleting category');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error deleting category');
        });
    }
}

// Auto-generate slug
document.getElementById('cat_name').addEventListener('input', function() {
    const slugField = document.getElementById('cat_slug');
    if(!slugField.value) {
        const slug = this.value
            .toLowerCase()
            .replace(/[^\w\s-]/g, '')
            .replace(/\s+/g, '-')
            .replace(/--+/g, '-')
            .trim();
        slugField.value = slug;
    }
});

// Form submission
document.getElementById('categoryForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const id = document.getElementById('category_id').value;
    const url = id ? `<?= root ?>admin/blogs/categories/update/${id}` : `<?= root ?>admin/blogs/categories/add`;
    
    fetch(url, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if(data.success) {
            location.reload();
        } else {
            alert(data.message || 'Error saving category');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error saving category');
    });
});

// Close modal when clicking outside
document.getElementById('categoryModal').addEventListener('click', function(e) {
    if(e.target === this) {
        closeModal();
    }
});

// Close modal with Escape key
document.addEventListener('keydown', function(e) {
    if(e.key === 'Escape' && !document.getElementById('categoryModal').classList.contains('hidden')) {
        closeModal();
    }
});
</script>