</main>
</div>

<!-- Admin Footer -->
<footer class="bg-white border-t border-gray-200 py-3 px-6" style="margin-left: 250px;" aria-label="Admin footer">
    <div class="flex items-center justify-between text-sm text-gray-500">
        <div>
            <span>&copy; <?php echo date('Y'); ?> Migori County Government. All rights reserved.</span>
        </div>
        <div class="flex items-center space-x-4">
            <span>PMC Portal v2.0</span>
            <span aria-hidden="true">•</span>
            <span>Last login: <?php 
                $current_admin = get_current_admin();
                if ($current_admin && isset($current_admin['last_login']) && $current_admin['last_login']) {
                    echo date('M j, Y g:i A', strtotime($current_admin['last_login']));
                } else {
                    echo 'Never';
                }
            ?></span>
        </div>
    </div>
</footer>

<!-- Combined JavaScript -->
<script>
// Document Management Functions
function editDocument(id, title, type, description) {
    const modal = document.getElementById('editModal');
    const form = document.getElementById('editForm');
    
    if (!modal || !form) return;
    
    // Decode HTML entities
    const decodedTitle = decodeHTMLEntities(title);
    const decodedDescription = decodeHTMLEntities(description);
    
    // Set form values
    document.getElementById('edit_document_id').value = id;
    document.getElementById('edit_document_title').value = decodedTitle;
    document.getElementById('edit_document_type').value = type;
    document.getElementById('edit_description').value = decodedDescription;
    
    // Show modal
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeEditModal() {
    const modal = document.getElementById('editModal');
    if (modal) {
        modal.classList.add('hidden');
        document.body.style.overflow = 'auto';
    }
}

function deleteDocument(id, title) {
    const decodedTitle = decodeHTMLEntities(title);
    
    if (!confirm(`Are you sure you want to delete the document "${decodedTitle}"?\n\nThis action cannot be undone and will be recorded in the audit trail.`)) {
        return false;
    }

    // Create and submit form
    const form = document.createElement('form');
    form.method = 'POST';
    form.style.display = 'none';

    const csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = 'csrf_token';
    csrfInput.value = '<?php echo generate_csrf_token(); ?>';

    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = 'delete';

    const idInput = document.createElement('input');
    idInput.type = 'hidden';
    idInput.name = 'document_id';
    idInput.value = id;

    form.appendChild(csrfInput);
    form.appendChild(actionInput);
    form.appendChild(idInput);

    document.body.appendChild(form);
    form.submit();
}

// Budget Management Functions
function confirmDelete(transactionId) {
    const deleteTransactionId = document.getElementById('deleteTransactionId');
    const modal = document.getElementById('deleteModal');
    
    if (deleteTransactionId && modal) {
        deleteTransactionId.value = transactionId;
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        setTimeout(() => {
            const deletionReason = document.getElementById('deletion_reason');
            if (deletionReason) deletionReason.focus();
        }, 100);
    }
}

function closeDeleteModal() {
    const modal = document.getElementById('deleteModal');
    const deletionReason = document.getElementById('deletion_reason');
    
    if (modal) {
        modal.classList.add('hidden');
        document.body.style.overflow = 'auto';
        
        if (deletionReason) {
            deletionReason.value = '';
        }
    }
}

function handleTransactionTypeChange(value) {
    const voucherField = document.getElementById('voucher_field');
    const disbursementField = document.getElementById('disbursement_field');
    const fundSourceField = document.getElementById('fund_source');

    if (voucherField && disbursementField) {
        voucherField.style.display = (value === 'expenditure' || value === 'disbursement') ? 'block' : 'none';
        disbursementField.style.display = (value === 'expenditure' || value === 'disbursement') ? 'block' : 'none';
    }

    if (fundSourceField) {
        if (value === 'expenditure') {
            const disbursedOption = Array.from(fundSourceField.options).find(opt => 
                opt.text.includes('Disbursed Allocation') || opt.text.includes('Disbursement')
            );
            if (disbursedOption) {
                disbursedOption.selected = true;
                fundSourceField.disabled = true;
                document.getElementById('other_fund_source_field').style.display = 'none';
            }
        } else {
            fundSourceField.disabled = false;
        }
    }
}

function toggleOtherFundSource() {
    const fundSource = document.getElementById('fund_source');
    if (!fundSource) return;
    
    const otherField = document.getElementById('other_fund_source_field');
    const otherInput = document.getElementById('other_fund_source');
    
    if (fundSource.value === 'Other') {
        otherField.style.display = 'block';
        otherInput.required = true;
    } else {
        otherField.style.display = 'none';
        otherInput.required = false;
        otherInput.value = '';
    }
}

function toggleOtherFundingCategory() {
    const fundingCategory = document.getElementById('funding_category');
    if (!fundingCategory) return;
    
    const otherField = document.getElementById('other_funding_category_field');
    const otherInput = document.getElementById('other_funding_category');
    
    otherField.style.display = fundingCategory.value === 'other' ? 'block' : 'none';
    if (fundingCategory.value !== 'other') otherInput.value = '';
}

// Helper Functions
function decodeHTMLEntities(text) {
    const textarea = document.createElement('textarea');
    textarea.innerHTML = text;
    return textarea.value;
}

function openMobileSidebar() {
    const mobileSidebar = document.getElementById('mobile-sidebar');
    const mobileOverlay = document.getElementById('mobile-sidebar-overlay');
    if (mobileSidebar && mobileOverlay) {
        mobileSidebar.classList.add('active');
        mobileOverlay.classList.add('active');
        document.body.classList.add('sidebar-open');
    }
}

function closeMobileSidebar() {
    const mobileSidebar = document.getElementById('mobile-sidebar');
    const mobileOverlay = document.getElementById('mobile-sidebar-overlay');
    if (mobileSidebar && mobileOverlay) {
        mobileSidebar.classList.remove('active');
        mobileOverlay.classList.remove('active');
        document.body.classList.remove('sidebar-open');
    }
}

function adjustFooter() {
    const footer = document.querySelector('footer[aria-label="Admin footer"]');
    if (footer) {
        footer.style.marginLeft = window.innerWidth >= 1024 ? '250px' : '0';
    }
}

async function updateStats() {
    try {
        const response = await fetch('ajax/getDashboardStats.php');
        if (!response.ok) throw new Error('Network response was not ok');
        const data = await response.json();

        const stats = {
            'totalProjects': data.totalProjects || 0,
            'activeProjects': data.activeProjects || 0,
            'completedProjects': data.completedProjects || 0,
            'pendingFeedback': data.pendingFeedback || 0
        };

        Object.entries(stats).forEach(([id, value]) => {
            const element = document.getElementById(id);
            if (element) element.textContent = value;
        });
    } catch (error) {
        console.error('Error updating stats:', error);
    }
}

// DOM Ready Handler
document.addEventListener('DOMContentLoaded', function() {
    // Mobile menu functionality
    const mobileMenuToggle = document.getElementById('mobile-menu-toggle');
    const mobileSidebar = document.getElementById('mobile-sidebar');
    const mobileOverlay = document.getElementById('mobile-sidebar-overlay');

    if (mobileMenuToggle && mobileSidebar && mobileOverlay) {
        mobileMenuToggle.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            openMobileSidebar();
        });

        mobileOverlay.addEventListener('click', closeMobileSidebar);
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeMobileSidebar();
        });

        const mobileNavLinks = mobileSidebar.querySelectorAll('.sidebar-nav-item');
        mobileNavLinks.forEach(link => {
            link.addEventListener('click', closeMobileSidebar);
        });

        mobileSidebar.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    }

    // Initialize transaction type
    const transactionType = document.getElementById('transaction_type');
    if (transactionType) {
        handleTransactionTypeChange(transactionType.value);
        transactionType.addEventListener('change', () => handleTransactionTypeChange(transactionType.value));
    }

    // Initialize other fields
    toggleOtherFundSource();
    toggleOtherFundingCategory();

    // Project search functionality
    const projectSearch = document.getElementById('project_search');
    const projectResults = document.getElementById('project_results');
    const projectIdInput = document.getElementById('project_id');

    if (projectSearch && projectResults && projectIdInput) {
        const projects = JSON.parse(document.querySelector('form[data-projects]')?.dataset?.projects || '[]');
        
        projectSearch.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();

            if (searchTerm.length < 2) {
                projectResults.classList.add('hidden');
                return;
            }

            const filteredProjects = projects.filter(project => 
                project.project_name.toLowerCase().includes(searchTerm)
            );

            if (filteredProjects.length === 0) {
                projectResults.innerHTML = '<div class="px-4 py-2 text-gray-500">No projects found</div>';
                projectResults.classList.remove('hidden');
                return;
            }

            projectResults.innerHTML = filteredProjects.map(project => `
                <div class="px-4 py-2 hover:bg-gray-100 cursor-pointer project-option" 
                     data-id="${project.id}" data-name="${project.project_name}">
                    ${project.project_name}
                </div>
            `).join('');

            projectResults.classList.remove('hidden');

            projectResults.querySelectorAll('.project-option').forEach(option => {
                option.addEventListener('click', function() {
                    projectSearch.value = this.dataset.name;
                    projectIdInput.value = this.dataset.id;
                    projectResults.classList.add('hidden');
                });
            });
        });

        document.addEventListener('click', function(e) {
            if (!projectSearch.contains(e.target) && !projectResults.contains(e.target)) {
                projectResults.classList.add('hidden');
            }
        });
    }

    // File input handlers
    ['transaction_document', 'document'].forEach(id => {
        const input = document.getElementById(id);
        if (input) {
            input.addEventListener('change', function() {
                const display = document.getElementById('file-name-display');
                if (display) {
                    display.textContent = this.files.length ? 'Selected: ' + this.files[0].name : '';
                }
            });
        }
    });

    // Auto-refresh stats if on dashboard
    if (typeof updateStats === 'function') {
        setInterval(updateStats, 30000);
        updateStats();
    }

    // Footer responsive adjustment
    adjustFooter();
    window.addEventListener('resize', adjustFooter);

    // Modal handlers
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('modal-overlay')) {
            const modal = e.target.closest('.modal');
            if (modal) {
                modal.classList.add('hidden');
                document.body.style.overflow = 'auto';
            }
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal:not(.hidden)').forEach(modal => {
                modal.classList.add('hidden');
                document.body.style.overflow = 'auto';
            });
        }
    });
});
</script>

<!-- Additional Styles -->
<style>
@media (max-width: 1023px) {
    footer[aria-label="Admin footer"] {
        margin-left: 0 !important;
    }
}
</style>

<!-- External JavaScript Files -->
<?php if (!empty($additional_js) && is_array($additional_js)): ?>
    <?php foreach ($additional_js as $js_file): ?>
        <script src="<?php echo htmlspecialchars($js_file, ENT_QUOTES); ?>"></script>
    <?php endforeach; ?>
<?php endif; ?>

<script src="<?php echo htmlspecialchars(BASE_URL, ENT_QUOTES); ?>assets/js/main.js"></script>
</body>
</html>