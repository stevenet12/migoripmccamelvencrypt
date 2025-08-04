// Consolidated Admin JavaScript for County Project Tracking System
// Includes admin functionality and dashboard charts

if (typeof window.AdminManager === 'undefined') {
    window.AdminManager = class AdminManager {
    constructor() {
        this.init();
        this.initMobileMenu();
    }

    init() {
        this.initDropdowns();
        this.initFileUpload();
        this.initProjectForm();
        this.initFeedbackManagement();
        this.initDashboard();
    }

    initMobileMenu() {
        const mobileMenuToggle = document.getElementById("mobile-menu-toggle");
        const mobileSidebar = document.getElementById("mobile-sidebar");
        const mobileOverlay = document.getElementById("mobile-sidebar-overlay");

        if (!mobileMenuToggle || !mobileSidebar || !mobileOverlay) {
            // Mobile menu elements not found - this is normal for public pages
            return;
        }

        // Enhanced click handling with proper event management
        mobileMenuToggle.addEventListener("click", (e) => {
            e.preventDefault();
            e.stopPropagation();
            this.toggleMobileSidebar(true);
        });

        mobileOverlay.addEventListener("click", (e) => {
            e.preventDefault();
            this.toggleMobileSidebar(false);
        });

        // Close sidebar when clicking outside
        document.addEventListener("click", (e) => {
            const isClickInsideSidebar = mobileSidebar.contains(e.target);
            const isClickOnToggle = mobileMenuToggle.contains(e.target);
            
            if (!isClickInsideSidebar && !isClickOnToggle && this.isMobileSidebarOpen()) {
                this.toggleMobileSidebar(false);
            }
        });

        // Prevent sidebar clicks from bubbling
        mobileSidebar.addEventListener("click", (e) => {
            e.stopPropagation();
        });

        // Close sidebar when navigation links are clicked
        const navLinks = mobileSidebar.querySelectorAll(".sidebar-nav-item");
        navLinks.forEach((link) => {
            link.addEventListener("click", (e) => {
                // Allow normal navigation but close sidebar
                setTimeout(() => {
                    this.toggleMobileSidebar(false);
                }, 100);
            });
        });

        // Escape key handler
        document.addEventListener("keydown", (e) => {
            if (e.key === "Escape" && this.isMobileSidebarOpen()) {
                this.toggleMobileSidebar(false);
            }
        });

        // Handle window resize
        window.addEventListener("resize", () => {
            // Close mobile sidebar on desktop resize
            if (window.innerWidth >= 1024 && this.isMobileSidebarOpen()) {
                this.toggleMobileSidebar(false);
            }
        });
    }

    isMobileSidebarOpen() {
        const mobileSidebar = document.getElementById("mobile-sidebar");
        return mobileSidebar && mobileSidebar.classList.contains("active");
    }

    toggleMobileSidebar(show) {
        const mobileSidebar = document.getElementById("mobile-sidebar");
        const mobileOverlay = document.getElementById("mobile-sidebar-overlay");

        if (!mobileSidebar || !mobileOverlay) return;

        if (show) {
            mobileSidebar.classList.add("active");
            mobileOverlay.classList.add("active");
            document.body.classList.add("sidebar-open");
        } else {
            mobileSidebar.classList.remove("active");
            mobileOverlay.classList.remove("active");
            document.body.classList.remove("sidebar-open");
        }
    }

    initDropdowns() {
        document.addEventListener("click", (e) => {
            const dropdowns = document.querySelectorAll('[id^="dropdown-"]');
            dropdowns.forEach((dropdown) => {
                if (!dropdown.contains(e.target) && !e.target.onclick) {
                    dropdown.classList.add("hidden");
                }
            });
        });
    }

    initFileUpload() {
        const fileUploadArea = document.getElementById("fileUploadArea");
        if (!fileUploadArea) return;

        ["dragover", "dragleave", "drop"].forEach((eventName) => {
            fileUploadArea.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
            });
        });

        fileUploadArea.addEventListener("dragover", () => {
            fileUploadArea.classList.add("dragover");
        });

        fileUploadArea.addEventListener("dragleave", () => {
            fileUploadArea.classList.remove("dragover");
        });

        fileUploadArea.addEventListener("drop", (e) => {
            fileUploadArea.classList.remove("dragover");
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                const csvFile = document.getElementById("csvFile");
                csvFile.files = files;
                this.handleFileSelect(csvFile);
            }
        });

        const uploadForm = document.getElementById("uploadForm");
        if (uploadForm) {
            uploadForm.addEventListener("submit", (e) => {
                e.preventDefault();
                this.uploadCSV();
            });
        }
    }

    initProjectForm() {
        window.loadSubCounties = (countyId) => {
            if (!countyId) {
                this.clearSelect("subCountyId");
                this.clearSelect("wardId");
                return;
            }

            fetch(
                `../api/locations.php?action=sub_counties&county_id=${countyId}`,
            )
                .then((response) => response.json())
                .then((data) => {
                    if (data.success) {
                        this.populateSelect(
                            "subCountyId",
                            data.data,
                            "id",
                            "name",
                            "Select Sub County",
                        );
                    }
                    this.clearSelect("wardId");
                })
                .catch((error) => {
                    console.error("Error loading sub counties:", error);
                    this.showNotification(
                        "Failed to load sub counties",
                        "error",
                    );
                });
        };

        window.loadWards = (subCountyId) => {
            if (!subCountyId) {
                this.clearSelect("wardId");
                return;
            }

            fetch(
                `../api/locations.php?action=wards&sub_county_id=${subCountyId}`,
            )
                .then((response) => response.json())
                .then((data) => {
                    if (data.success) {
                        this.populateSelect(
                            "wardId",
                            data.data,
                            "id",
                            "name",
                            "Select Ward",
                        );
                    }
                })
                .catch((error) => {
                    console.error("Error loading wards:", error);
                    this.showNotification("Failed to load wards", "error");
                });
        };
    }

    initFeedbackManagement() {
        const responseForm = document.getElementById("responseForm");
        if (responseForm) {
            responseForm.addEventListener("submit", (e) => {
                e.preventDefault();
                this.submitResponse();
            });
        }
    }

    initDashboard() {
        const totalProjectsElement = document.getElementById("totalProjects");
        if (totalProjectsElement) {
            this.updateStats();
            setInterval(() => this.updateStats(), 30000);
        }
    }

    clearSelect(selectId) {
        const select = document.getElementById(selectId);
        if (select) {
            select.innerHTML = '<option value="">Select...</option>';
        }
    }

    populateSelect(selectId, data, valueField, textField, placeholder) {
        const select = document.getElementById(selectId);
        if (!select) return;

        select.innerHTML = `<option value="">${placeholder}</option>`;
        data.forEach((item) => {
            const option = document.createElement("option");
            option.value = item[valueField];
            option.textContent = item[textField];
            select.appendChild(option);
        });
    }

    async uploadCSV() {
        const form = document.getElementById("uploadForm");
        const submitBtn = document.getElementById("submitBtn");
        const submitText = document.getElementById("submitText");
        const uploadingText = document.getElementById("uploadingText");

        this.setLoadingState(submitBtn, submitText, uploadingText, true);

        try {
            const formData = new FormData(form);
            const response = await fetch("api/uploadCsv.php", {
                method: "POST",
                body: formData,
            });

            const data = await response.json();

            if (data.success) {
                this.showNotification(data.message, "success");
                this.showImportResults(data);
                this.clearFile();
            } else {
                this.showNotification(data.message || "Import failed", "error");
                if (data.errors?.length > 0) {
                    this.showImportResults(data);
                }
            }
        } catch (error) {
            console.error("Upload error:", error);
            this.showNotification("Upload failed", "error");
        } finally {
            this.setLoadingState(submitBtn, submitText, uploadingText, false);
        }
    }

    setLoadingState(btn, submitText, loadingText, isLoading) {
        btn.disabled = isLoading;
        if (isLoading) {
            submitText?.classList.add("hidden");
            loadingText?.classList.remove("hidden");
        } else {
            submitText?.classList.remove("hidden");
            loadingText?.classList.add("hidden");
        }
    }

    showImportResults(data) {
        const resultsDiv = document.getElementById("importResults");
        const contentDiv = document.getElementById("resultsContent");

        if (!resultsDiv || !contentDiv) return;

        const html = `
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="text-center p-4 bg-blue-50 rounded-lg">
                    <div class="text-2xl font-bold text-blue-600">${data.total_rows || 0}</div>
                    <div class="text-sm text-blue-600">Total Rows</div>
                </div>
                <div class="text-center p-4 bg-green-50 rounded-lg">
                    <div class="text-2xl font-bold text-green-600">${data.successful_imports || 0}</div>
                    <div class="text-sm text-green-600">Successful</div>
                </div>
                <div class="text-center p-4 bg-red-50 rounded-lg">
                    <div class="text-2xl font-bold text-red-600">${data.failed_imports || 0}</div>
                    <div class="text-sm text-red-600">Failed</div>
                </div>
            </div>
            ${
                data.errors?.length
                    ? `
                <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                    <h4 class="font-medium text-red-800 mb-2">Import Errors:</h4>
                    <div class="max-h-40 overflow-y-auto">
                        <ul class="space-y-1 text-sm text-red-700">
                            ${data.errors.map((error) => `<li>• ${this.escapeHtml(error)}</li>`).join("")}
                        </ul>
                    </div>
                </div>
            `
                    : ""
            }
        `;

        contentDiv.innerHTML = html;
        resultsDiv.classList.remove("hidden");
        resultsDiv.scrollIntoView({ behavior: "smooth" });
    }

    escapeHtml(text) {
        const div = document.createElement("div");
        div.textContent = text;
        return div.innerHTML;
    }

    async submitResponse() {
        const form = document.getElementById("responseForm");
        const formData = new FormData(form);

        try {
            const response = await fetch(window.location.href, {
                method: "POST",
                body: formData,
            });

            if (response.ok) {
                this.showNotification("Response sent successfully", "success");
                this.closeResponseModal();
                setTimeout(() => window.location.reload(), 1000);
            } else {
                throw new Error("Response failed");
            }
        } catch (error) {
            console.error("Response error:", error);
            this.showNotification("Failed to send response", "error");
        }
    }

    closeResponseModal() {
        const modal = document.getElementById("responseModal");
        if (modal) {
            modal.classList.add("hidden");
            document.body.style.overflow = "auto";
        }
    }

    updateStats() {
        const baseUrl = window.BASE_URL || "../";
        fetch(baseUrl + "api/dashboardStats.php")
            .then((response) => response.json())
            .then((data) => {
                if (data.success) {
                    const stats = data.stats;
                    this.updateElement("totalProjects", stats.total_projects);
                    this.updateElement(
                        "ongoingProjects",
                        stats.ongoing_projects,
                    );
                    this.updateElement(
                        "completedProjects",
                        stats.completed_projects,
                    );
                }
            })
            .catch((error) => console.error("Error updating stats:", error));
    }

    updateElement(id, value) {
        const element = document.getElementById(id);
        if (element) {
            element.textContent = value || "0";
        }
    }

    showNotification(message, type = "info") {
        if (window.Utils?.showNotification) {
            window.Utils.showNotification(message, type);
        } else {
            alert(message);
        }
    }

    handleFileSelect(input) {
        const file = input.files[0];
        if (!file) return;

        const fileInfo = document.getElementById("fileInfo");
        const fileName = document.getElementById("fileName");
        const fileSize = document.getElementById("fileSize");
        const submitBtn = document.getElementById("submitBtn");

        if (file.type !== "text/csv" && !file.name.endsWith(".csv")) {
            this.showNotification("Please select a CSV file", "error");
            input.value = "";
            return;
        }

        if (file.size > 5 * 1024 * 1024) {
            this.showNotification("File size must be less than 5MB", "error");
            input.value = "";
            return;
        }

        if (fileName) fileName.textContent = file.name;
        if (fileSize)
            fileSize.textContent = `${(file.size / 1024 / 1024).toFixed(2)} MB`;
        if (fileInfo) fileInfo.classList.remove("hidden");
        if (submitBtn) submitBtn.disabled = false;
    }

    clearFile() {
        const csvFile = document.getElementById("csvFile");
        const fileInfo = document.getElementById("fileInfo");
        const submitBtn = document.getElementById("submitBtn");

        if (csvFile) csvFile.value = "";
        if (fileInfo) fileInfo.classList.add("hidden");
        if (submitBtn) submitBtn.disabled = true;
    }
    };
}

// Dashboard Charts Class
if (typeof window.DashboardCharts === "undefined") {
    window.DashboardCharts = class DashboardCharts {
        constructor() {
            this.chartInstances = {};
            this.initCharts();
        }

        destroyChart(chartId) {
            if (this.chartInstances[chartId]) {
                this.chartInstances[chartId].destroy();
                delete this.chartInstances[chartId];
            }

            const canvas = document.getElementById(chartId);
            if (canvas) {
                const existingChart = Chart.getChart(canvas);
                if (existingChart) {
                    existingChart.destroy();
                }
            }
        }

        initCharts() {
            if (typeof window.dashboardData === "undefined") {
                console.warn("Dashboard data not available");
                return;
            }

            Chart.defaults.font.family = "Inter, system-ui, sans-serif";
            Chart.defaults.color = "#6b7280";

            this.initStatusDistributionChart();
            this.initBudgetExpenditureChart();
            this.initMonthlyTrendsChart();
            this.initProgressDistributionChart();
            this.initFeedbackStatusChart();
        }

        initStatusDistributionChart() {
            const statusData = window.dashboardData.projects_by_status;
            const statusLabels = statusData.map(
                (item) =>
                    item.status.charAt(0).toUpperCase() + item.status.slice(1),
            );
            const statusCounts = statusData.map((item) => item.count);
            const statusColors = [
                "#f59e0b",
                "#3b82f6",
                "#10b981",
                "#f97316",
                "#ef4444",
                "#8b5cf6",
            ];

            const element = document.getElementById("statusDistributionChart");
            if (!element) return;

            this.destroyChart("statusDistributionChart");

            this.chartInstances["statusDistributionChart"] = new Chart(
                element,
                {
                    type: "doughnut",
                    data: {
                        labels: statusLabels,
                        datasets: [
                            {
                                data: statusCounts,
                                backgroundColor: statusColors,
                                borderWidth: 3,
                                borderColor: "#ffffff",
                            },
                        ],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: "bottom",
                                labels: { padding: 20, usePointStyle: true },
                            },
                        },
                    },
                },
            );
        }

        initBudgetExpenditureChart() {
            const budgetExpData = window.dashboardData.budget_expenditure;
            const element = document.getElementById("budgetExpenditureChart");
            if (!element) return;

            this.destroyChart("budgetExpenditureChart");

            this.chartInstances["budgetExpenditureChart"] = new Chart(element, {
                type: "bar",
                data: {
                    labels: budgetExpData.map((item) => item.department),
                    datasets: [
                        {
                            label: "Allocated Budget",
                            data: budgetExpData.map(
                                (item) => item.allocated_budget,
                            ),
                            backgroundColor: "rgba(59, 130, 246, 0.8)",
                            borderColor: "rgb(59, 130, 246)",
                            borderWidth: 1,
                        },
                        {
                            label: "Total Expenditure",
                            data: budgetExpData.map(
                                (item) => item.total_expenditure,
                            ),
                            backgroundColor: "rgba(16, 185, 129, 0.8)",
                            borderColor: "rgb(16, 185, 129)",
                            borderWidth: 1,
                        },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function (value) {
                                    return (
                                        "KES " +
                                        (value / 1000000).toFixed(1) +
                                        "M"
                                    );
                                },
                            },
                        },
                    },
                    plugins: {
                        legend: { position: "top" },
                    },
                },
            });
        }

        initMonthlyTrendsChart() {
            const monthlyData = window.dashboardData.monthly_trends;
            const element = document.getElementById("monthlyTrendsChart");
            if (!element) return;

            this.destroyChart("monthlyTrendsChart");

            this.chartInstances["monthlyTrendsChart"] = new Chart(element, {
                type: "line",
                data: {
                    labels: monthlyData.map((item) => item.month),
                    datasets: [
                        {
                            label: "Projects Created",
                            data: monthlyData.map(
                                (item) => item.projects_created,
                            ),
                            borderColor: "rgb(147, 51, 234)",
                            backgroundColor: "rgba(147, 51, 234, 0.1)",
                            tension: 0.4,
                            yAxisID: "y",
                        },
                        {
                            label: "Monthly Budget (Millions)",
                            data: monthlyData.map(
                                (item) => item.monthly_budget / 1000000,
                            ),
                            borderColor: "rgb(245, 158, 11)",
                            backgroundColor: "rgba(245, 158, 11, 0.1)",
                            tension: 0.4,
                            yAxisID: "y1",
                        },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            type: "linear",
                            display: true,
                            position: "left",
                            title: {
                                display: true,
                                text: "Number of Projects",
                            },
                        },
                        y1: {
                            type: "linear",
                            display: true,
                            position: "right",
                            title: {
                                display: true,
                                text: "Budget (Millions KES)",
                            },
                            grid: { drawOnChartArea: false },
                        },
                    },
                },
            });
        }

        initProgressDistributionChart() {
            const progressStats = window.dashboardData.progress_stats;
            const element = document.getElementById(
                "progressDistributionChart",
            );
            if (!element) return;

            this.destroyChart("progressDistributionChart");

            this.chartInstances["progressDistributionChart"] = new Chart(
                element,
                {
                    type: "bar",
                    data: {
                        labels: ["0-25%", "26-50%", "51-75%", "76-100%"],
                        datasets: [
                            {
                                label: "Number of Projects",
                                data: [
                                    progressStats.projects_0_25,
                                    progressStats.projects_26_50,
                                    progressStats.projects_51_75,
                                    progressStats.projects_76_100,
                                ],
                                backgroundColor: [
                                    "rgba(239, 68, 68, 0.8)",
                                    "rgba(245, 158, 11, 0.8)",
                                    "rgba(59, 130, 246, 0.8)",
                                    "rgba(16, 185, 129, 0.8)",
                                ],
                                borderWidth: 1,
                            },
                        ],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: { y: { beginAtZero: true } },
                    },
                },
            );
        }

        initFeedbackStatusChart() {
            const feedbackStats = window.dashboardData.feedback_stats;
            const element = document.getElementById("feedbackStatusChart");
            if (!element) return;

            this.destroyChart("feedbackStatusChart");

            this.chartInstances["feedbackStatusChart"] = new Chart(element, {
                type: "pie",
                data: {
                    labels: ["Pending", "Reviewed", "Responded"],
                    datasets: [
                        {
                            data: [
                                feedbackStats.pending_feedback,
                                feedbackStats.reviewed_feedback,
                                feedbackStats.responded_feedback,
                            ],
                            backgroundColor: ["#fbbf24", "#3b82f6", "#10b981"],
                            borderWidth: 2,
                            borderColor: "#ffffff",
                        },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: "bottom" },
                    },
                },
            });
        }

        destroyAllCharts() {
            Object.keys(this.chartInstances).forEach((chartId) => {
                this.destroyChart(chartId);
            });
        }
    };
}

// Global functions for HTML onclick handlers
window.handleFileSelect = (input) =>
    window.adminManager?.handleFileSelect(input);
window.clearFile = () => window.adminManager?.clearFile();

window.downloadSampleCSV = () => {
    const csvContent = `project_name,description,department,ward,sub_county,county,year,status,progress_percentage,contractor_name,start_date,expected_completion_date,location_coordinates,location_address
New Water Plant,Construction of new water treatment plant,Water and Sanitation,Central Ward,Nairobi Central,Nairobi,2024,ongoing,45,ABC Contractors,2024-01-15,2024-12-31,-1.2921,36.8219,123 Main Street Nairobi
Road Construction,Tarmacking of rural roads,Roads and Transport,West Ward,Kiambu East,Kiambu,2024,planning,0,XYZ Construction,2024-03-01,2024-11-30,-1.1744,36.9482,Rural Road Network`;

    const blob = new Blob([csvContent], { type: "text/csv" });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.style.display = "none";
    a.href = url;
    a.download = "sample_projects.csv";
    document.body.appendChild(a);
    a.click();
    window.URL.revokeObjectURL(url);
};

// Additional admin functions
window.showProjectForm = (project = null) => {
    const modal = document.getElementById("projectModal");
    const modalTitle = document.getElementById("modalTitle");
    const formAction = document.getElementById("formAction");
    const submitText = document.getElementById("submitText");
    const form = document.getElementById("projectForm");

    if (project) {
        modalTitle.textContent = "Edit Project";
        formAction.value = "update";
        submitText.textContent = "Update Project";
        populateProjectForm(project);
    } else {
        modalTitle.textContent = "Add New Project";
        formAction.value = "create";
        submitText.textContent = "Create Project";
        form.reset();
        document.getElementById("projectId").value = "";
    }

    modal.classList.remove("hidden");
    document.body.style.overflow = "hidden";
};

window.closeProjectForm = () => {
    const modal = document.getElementById("projectModal");
    modal.classList.add("hidden");
    document.body.style.overflow = "auto";
};

window.deleteProject = (projectId, projectName) => {
    if (
        confirm(
            `Are you sure you want to delete "${projectName}"? This action cannot be undone.`,
        )
    ) {
        const form = document.createElement("form");
        form.method = "POST";
        form.action = "projects.php";

        const inputs = [
            {
                name: "csrf_token",
                value:
                    document.querySelector('input[name="csrf_token"]')?.value ||
                    "",
            },
            { name: "action", value: "delete" },
            { name: "project_id", value: projectId },
        ];

        inputs.forEach(({ name, value }) => {
            const input = document.createElement("input");
            input.type = "hidden";
            input.name = name;
            input.value = value;
            form.appendChild(input);
        });

        document.body.appendChild(form);
        form.submit();
    }
};

window.toggleDropdown = (feedbackId) => {
    const dropdown = document.getElementById(`dropdown-${feedbackId}`);
    const isHidden = dropdown.classList.contains("hidden");

    document
        .querySelectorAll('[id^="dropdown-"]')
        .forEach((d) => d.classList.add("hidden"));

    if (isHidden) {
        dropdown.classList.remove("hidden");
    }
};

window.showResponseForm = (feedbackId, subject) => {
    document.getElementById("responseFeedbackId").value = feedbackId;
    document.getElementById("responseModalTitle").textContent =
        `Respond to: ${subject}`;
    document.getElementById("responseModal").classList.remove("hidden");
    document.body.style.overflow = "hidden";
};

window.closeResponseModal = () => window.adminManager?.closeResponseModal();

window.updateStatus = (feedbackId, status) => {
    document.getElementById("statusFeedbackId").value = feedbackId;
    document.getElementById("newStatus").value = status;
    document.getElementById("statusForm").submit();
};

window.deleteFeedback = (feedbackId, subject) => {
    if (confirm(`Are you sure you want to delete the feedback "${subject}"?`)) {
        document.getElementById("deleteFeedbackId").value = feedbackId;
        document.getElementById("deleteForm").submit();
    }
};

// Notification functions
function showNotification(message, type = "info") {
    const notification = document.createElement("div");
    notification.className = `fixed top-4 right-4 z-50 p-4 rounded-md shadow-lg transition-all duration-300 ${
        type === "success"
            ? "bg-green-500 text-white"
            : type === "error"
              ? "bg-red-500 text-white"
              : type === "warning"
                ? "bg-yellow-500 text-black"
                : "bg-blue-500 text-white"
    }`;
    notification.innerHTML = `
        <div class="flex items-center">
            <i class="fas ${
                type === "success"
                    ? "fa-check-circle"
                    : type === "error"
                      ? "fa-exclamation-circle"
                      : type === "warning"
                        ? "fa-exclamation-triangle"
                        : "fa-info-circle"
            } mr-2"></i>
            <span>${message}</span>
            <button onclick="this.parentElement.parentElement.remove()" class="ml-4 text-white hover:text-gray-200">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;

    document.body.appendChild(notification);

    setTimeout(() => {
        if (notification.parentElement) {
            notification.remove();
        }
    }, 5000);
}

// Styled notification for comment system
function showStyledNotification(message, type = "success") {
    const notification = document.createElement("div");

    let bgColor, textColor, icon;
    switch (type) {
        case 'success':
            bgColor = 'bg-green-100 border-green-500';
            textColor = 'text-green-800';
            icon = 'fa-check-circle text-green-600';
            break;
        case 'review':
            bgColor = 'bg-blue-100 border-blue-500';
            textColor = 'text-blue-800';
            icon = 'fa-clock text-blue-600';
            break;
        case 'language':
            bgColor = 'bg-red-100 border-red-500';
            textColor = 'text-red-800';
            icon = 'fa-language text-red-600';
            break;
        case 'error':
        default:
            bgColor = 'bg-red-100 border-red-500';
            textColor = 'text-red-800';
            icon = 'fa-exclamation-circle text-red-600';
            break;
    }

    notification.className = `fixed top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 z-50 p-6 rounded-lg shadow-2xl border-l-4 ${bgColor} ${textColor} max-w-md w-full mx-4 animate-pulse`;
    notification.style.animation = 'fadeInOut 4s ease-in-out forwards';

    notification.innerHTML = `
        <div class="flex items-start">
            <i class="fas ${icon} mr-3 mt-1 text-lg"></i>
            <div class="flex-1">
                <p class="font-medium text-sm leading-relaxed">${message}</p>
            </div>
        </div>
    `;

    // Add CSS animation
    if (!document.getElementById('notification-styles')) {
        const style = document.createElement('style');
        style.id = 'notification-styles';
        style.textContent = `
            @keyframes fadeInOut {
                0% { opacity: 0; transform: translate(-50%, -50%) scale(0.8); }
                15% { opacity: 1; transform: translate(-50%, -50%) scale(1); }
                85% { opacity: 1; transform: translate(-50%, -50%) scale(1); }
                100% { opacity: 0; transform: translate(-50%, -50%) scale(0.8); }
            }
        `;
        document.head.appendChild(style);
    }

    document.body.appendChild(notification);

    setTimeout(() => {
        if (notification.parentElement) {
            notification.remove();
        }
    }, 4000);
}

window.applyFilters = function () {
    const departmentFilter = document.getElementById("departmentFilter");
    const statusFilter = document.getElementById("statusFilter");
    const yearFilter = document.getElementById("yearFilter");
    const subCountyFilter = document.getElementById("subCountyFilter");

    const departmentId = departmentFilter ? departmentFilter.value : "";
    const status = statusFilter ? statusFilter.value : "";
    const year = yearFilter ? yearFilter.value : "";
    const subCounty = subCountyFilter ? subCountyFilter.value : "";

    const params = new URLSearchParams(window.location.search);

    if (departmentId) {
        params.set("department", departmentId);
    } else {
        params.delete("department");
    }

    if (status) {
        params.set("status", status);
    } else {
        params.delete("status");
    }

    if (year) {
        params.set("year", year);
    } else {
        params.delete("year");
    }

    if (subCounty) {
        params.set("sub_county", subCounty);
    } else {
        params.delete("sub_county");
    }

    window.location.href = window.location.pathname + "?" + params.toString();
};

// Preserve filter selections on page load
window.preserveFilterSelections = function() {
    const urlParams = new URLSearchParams(window.location.search);

    // Preserve all filter selections
    const filters = ['departmentFilter', 'statusFilter', 'yearFilter', 'subCountyFilter'];
    const paramMap = {
        'departmentFilter': 'department',
        'statusFilter': 'status', 
        'yearFilter': 'year',
        'subCountyFilter': 'sub_county'
    };

    filters.forEach(filterId => {
        const filterElement = document.getElementById(filterId);
        const paramName = paramMap[filterId];

        if (filterElement && urlParams.has(paramName)) {
            const paramValue = urlParams.get(paramName);
            filterElement.value = paramValue;
        }
    });
};

// Global functions for HTML onclick handlers
window.handleFileSelect = (input) =>
    window.adminManager?.handleFileSelect(input);
window.clearFile = () => window.adminManager?.clearFile();

window.downloadSampleCSV = () => {
    const csvContent = `project_name,description,department,ward,sub_county,county,year,status,progress_percentage,contractor_name,start_date,expected_completion_date,location_coordinates,location_address
New Water Plant,Construction of new water treatment plant,Water and Sanitation,Central Ward,Nairobi Central,Nairobi,2024,ongoing,45,ABC Contractors,2024-01-15,2024-12-31,-1.2921,36.8219,123 Main Street Nairobi
Road Construction,Tarmacking of rural roads,Roads and Transport,West Ward,Kiambu East,Kiambu,2024,planning,0,XYZ Construction,2024-03-01,2024-11-30,-1.1744,36.9482,Rural Road Network`;

    const blob = new Blob([csvContent], { type: "text/csv" });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.style.display = "none";
    a.href = url;
    a.download = "sample_projects.csv";
    document.body.appendChild(a);
    a.click();
    window.URL.revokeObjectURL(url);
};

// Additional admin functions
window.showProjectForm = (project = null) => {
    const modal = document.getElementById("projectModal");
    const modalTitle = document.getElementById("modalTitle");
    const formAction = document.getElementById("formAction");
    const submitText = document.getElementById("submitText");
    const form = document.getElementById("projectForm");

    if (project) {
        modalTitle.textContent = "Edit Project";
        formAction.value = "update";
        submitText.textContent = "Update Project";
        populateProjectForm(project);
    } else {
        modalTitle.textContent = "Add New Project";
        formAction.value = "create";
        submitText.textContent = "Create Project";
        form.reset();
        document.getElementById("projectId").value = "";
    }

    modal.classList.remove("hidden");
    document.body.style.overflow = "hidden";
};

window.closeProjectForm = () => {
    const modal = document.getElementById("projectModal");
    modal.classList.add("hidden");
    document.body.style.overflow = "auto";
};

window.deleteProject = (projectId, projectName) => {
    if (
        confirm(
            `Are you sure you want to delete "${projectName}"? This action cannot be undone.`,
        )
    ) {
        const form = document.createElement("form");
        form.method = "POST";
        form.action = "projects.php";

        const inputs = [
            {
                name: "csrf_token",
                value:
                    document.querySelector('input[name="csrf_token"]')?.value ||
                    "",
            },
            { name: "action", value: "delete" },
            { name: "project_id", value: projectId },
        ];

        inputs.forEach(({ name, value }) => {
            const input = document.createElement("input");
            input.type = "hidden";
            input.name = name;
            input.value = value;
            form.appendChild(input);
        });

        document.body.appendChild(form);
        form.submit();
    }
};

window.toggleDropdown = (feedbackId) => {
    const dropdown = document.getElementById(`dropdown-${feedbackId}`);
    const isHidden = dropdown.classList.contains("hidden");

    document
        .querySelectorAll('[id^="dropdown-"]')
        .forEach((d) => d.classList.add("hidden"));

    if (isHidden) {
        dropdown.classList.remove("hidden");
    }
};

window.showResponseForm = (feedbackId, subject) => {
    document.getElementById("responseFeedbackId").value = feedbackId;
    document.getElementById("responseModalTitle").textContent =
        `Respond to: ${subject}`;
    document.getElementById("responseModal").classList.remove("hidden");
    document.body.style.overflow = "hidden";
};

window.closeResponseModal = () => window.adminManager?.closeResponseModal();

window.updateStatus = (feedbackId, status) => {
    document.getElementById("statusFeedbackId").value = feedbackId;
    document.getElementById("newStatus").value = status;
    document.getElementById("statusForm").submit();
};

window.deleteFeedback = (feedbackId, subject) => {
    if (confirm(`Are you sure you want to delete the feedback "${subject}"?`)) {
        document.getElementById("deleteFeedbackId").value = feedbackId;
        document.getElementById("deleteForm").submit();
    }
};

// Notification functions
function showNotification(message, type = "info") {
    const notification = document.createElement("div");
    notification.className = `fixed top-4 right-4 z-50 p-4 rounded-md shadow-lg transition-all duration-300 ${
        type === "success"
            ? "bg-green-500 text-white"
            : type === "error"
              ? "bg-red-500 text-white"
              : type === "warning"
                ? "bg-yellow-500 text-black"
                : "bg-blue-500 text-white"
    }`;
    notification.innerHTML = `
        <div class="flex items-center">
            <i class="fas ${
                type === "success"
                    ? "fa-check-circle"
                    : type === "error"
                      ? "fa-exclamation-circle"
                      : type === "warning"
                        ? "fa-exclamation-triangle"
                        : "fa-info-circle"
            } mr-2"></i>
            <span>${message}</span>
            <button onclick="this.parentElement.parentElement.remove()" class="ml-4 text-white hover:text-gray-200">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;

    document.body.appendChild(notification);

    setTimeout(() => {
        if (notification.parentElement) {
            notification.remove();
        }
    }, 5000);
}

// Styled notification for comment system
function showStyledNotification(message, type = "success") {
    const notification = document.createElement("div");

    let bgColor, textColor, icon;
    switch (type) {
        case 'success':
            bgColor = 'bg-green-100 border-green-500';
            textColor = 'text-green-800';
            icon = 'fa-check-circle text-green-600';
            break;
        case 'review':
            bgColor = 'bg-blue-100 border-blue-500';
            textColor = 'text-blue-800';
            icon = 'fa-clock text-blue-600';
            break;
        case 'language':
            bgColor = 'bg-red-100 border-red-500';
            textColor = 'text-red-800';
            icon = 'fa-language text-red-600';
            break;
        case 'error':
        default:
            bgColor = 'bg-red-100 border-red-500';
            textColor = 'text-red-800';
            icon = 'fa-exclamation-circle text-red-600';
            break;
    }

    notification.className = `fixed top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 z-50 p-6 rounded-lg shadow-2xl border-l-4 ${bgColor} ${textColor} max-w-md w-full mx-4 animate-pulse`;
    notification.style.animation = 'fadeInOut 4s ease-in-out forwards';

    notification.innerHTML = `
        <div class="flex items-start">
            <i class="fas ${icon} mr-3 mt-1 text-lg"></i>
            <div class="flex-1">
                <p class="font-medium text-sm leading-relaxed">${message}</p>
            </div>
        </div>
    `;

    // Add CSS animation
    if (!document.getElementById('notification-styles')) {
        const style = document.createElement('style');
        style.id = 'notification-styles';
        style.textContent = `
            @keyframes fadeInOut {
                0% { opacity: 0; transform: translate(-50%, -50%) scale(0.8); }
                15% { opacity: 1; transform: translate(-50%, -50%) scale(1); }
                85% { opacity: 1; transform: translate(-50%, -50%) scale(1); }
                100% { opacity: 0; transform: translate(-50%, -50%) scale(0.8); }
            }
        `;
        document.head.appendChild(style);
    }

    document.body.appendChild(notification);

    setTimeout(() => {
        if (notification.parentElement) {
            notification.remove();
        }
    }, 4000);
}

// Initialize admin manager and charts
document.addEventListener("DOMContentLoaded", () => {
    if (!window.adminManager && window.AdminManager) {
        window.adminManager = new window.AdminManager();
    }

    // Initialize dashboard charts if available
    if (
        typeof Chart !== "undefined" &&
        typeof window.DashboardCharts !== "undefined"
    ) {
        if (window.dashboardCharts) {
            window.dashboardCharts.destroyAllCharts();
        }
        window.dashboardCharts = new window.DashboardCharts();
    }

    // Initialize project detail map if on project details page
    if (typeof window.projectData !== 'undefined' && window.projectData.coordinates && typeof L !== 'undefined') {
        setTimeout(() => {
            initializeProjectDetailMap();
        }, 100);
    }
    
     // Preserve filter selections on page load
    if (typeof window.preserveFilterSelections === 'function') {
        window.preserveFilterSelections();
    }
});

// Project detail map initialization with proper cleanup
function initializeProjectDetailMap() {
    // Use a global variable to track the map instance
    if (typeof window.projectDetailMapInstance !== 'undefined' && window.projectDetailMapInstance) {
        window.projectDetailMapInstance.remove();
        window.projectDetailMapInstance = null;
    }
    const mapContainer = document.getElementById('projectMap');
    if (!mapContainer || !window.projectData.coordinates) {
        return;
    }

    try {
        const coords = parseCoordinates(window.projectData.coordinates);
        if (!coords || coords.length !== 2) return;

        const [lat, lng] = coords;
        if (isNaN(lat) || isNaN(lng)) return;

        // Clean up existing map instance
        if (window.projectDetailMapInstance) {
            window.projectDetailMapInstance.remove();
            window.projectDetailMapInstance = null;
        }

        // Clear container
        mapContainer.innerHTML = '';

        // Check if container already has a map
        if (mapContainer._leaflet_id) {
            delete mapContainer._leaflet_id;
        }

        window.projectDetailMapInstance = L.map(mapContainer).setView([lat, lng], 15);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors',
            maxZoom: 18
        }).addTo(projectDetailMapInstance);

        // Add marker with project info
        const marker = L.marker([lat, lng]).addTo(window.projectDetailMapInstance);

        if (window.projectData.name) {
            marker.bindPopup(`
                <strong>${escapeHtml(window.projectData.name)}</strong><br>
                ${escapeHtml(window.projectData.ward)}, ${escapeHtml(window.projectData.sub_county)}
            `);
        }

    } catch (error) {
        console.error('Error initializing project detail map:', error);
    }
}

function parseCoordinates(coordinateString) {
    if (!coordinateString) return null;

    try {
        if (coordinateString.startsWith('[')) {
            return JSON.parse(coordinateString);
        }

        if (typeof coordinateString === 'string' && coordinateString.includes(',')) {
            const parts = coordinateString.split(',');
            if (parts.length === 2) {
                const lat = parseFloat(parts[0].trim());
                const lng = parseFloat(parts[1].trim());
                if (!isNaN(lat) && !isNaN(lng)) {
                    return [lat, lng];
                }
            }
        }

        return null;
    } catch (e) {
        return null;
    }
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Global functions for comment handling
window.replyToComment = function(commentId, authorName) {
    const formTitle = document.getElementById('commentFormTitle');
    const replyingToInfo = document.getElementById('replyingToInfo');
    const replyingToName = document.getElementById('replyingToName');
    const parentCommentIdField = document.getElementById('parentCommentId');
    const submitBtn = document.getElementById('submitBtn');
    const commentForm = document.getElementById('commentForm');

    if (formTitle && replyingToInfo && replyingToName && parentCommentIdField && submitBtn) {
        formTitle.textContent = 'Reply to Comment';
        replyingToInfo.classList.remove('hidden');
        replyingToName.textContent = authorName;
        parentCommentIdField.value = commentId;
        submitBtn.innerHTML = '<i class="fas fa-reply mr-2"></i>Submit Reply';

        // Scroll to the comment form
        if (commentForm) {
            commentForm.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }
};

window.cancelReply = function() {
    const formTitle = document.getElementById('commentFormTitle');
    const replyingToInfo = document.getElementById('replyingToInfo');
    const parentCommentIdField = document.getElementById('parentCommentId');
    const submitBtn = document.getElementById('submitBtn');

    if (formTitle && replyingToInfo && parentCommentIdField && submitBtn) {
        formTitle.textContent = 'Join the Discussion';
        replyingToInfo.classList.add('hidden');
        parentCommentIdField.value = '0';
        submitBtn.innerHTML = '<i class="fas fa-paper-plane mr-2"></i>Submit Comment';
    }
};

window.loadMoreReplies = async function(parentId, currentOffset) {
    const loadButton = event.target;
    const originalText = loadButton.innerHTML;

    // Show loading state
    loadButton.disabled = true;
    loadButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Loading...';

    try {
        const response = await fetch(`./api/loadMoreReplies.php?parent_id=${parentId}&offset=${currentOffset}&limit=5`);
        const data = await response.json();

        if (data.success && data.replies.length > 0) {
            const repliesContainer = document.querySelector(`[data-comment-id="${parentId}"] .replies-container`);
            const loadMoreContainer = loadButton.closest('.load-more-replies-container');

            // Create new reply elements
            data.replies.forEach(reply => {
                const replyElement = createReplyElement(reply);
                repliesContainer.insertBefore(replyElement, loadMoreContainer);
            });

            // Update or remove load more button
            if (data.has_more) {
                const newOffset = currentOffset + data.replies.length;
                loadButton.setAttribute('onclick', `loadMoreReplies(${parentId}, ${newOffset})`);
                loadButton.innerHTML = `<i class="fas fa-chevron-down mr-2"></i>Load ${Math.min(data.remaining, 5)} more replies`;
                loadButton.disabled = false;
            } else {
                loadMoreContainer.remove();
            }
        } else {
            loadButton.style.display = 'none';
        }
    } catch (error) {
        console.error('Error loading more replies:', error);
        loadButton.innerHTML = originalText;
        loadButton.disabled = false;
        showEnhancedNotification('Failed to load more replies', 'error');
    }
};

function createReplyElement(reply) {
    const replyDiv = document.createElement('div');
    replyDiv.className = 'reply-item bg-gray-50 rounded-lg border border-gray-200 p-3';

    const pendingBadge = reply.is_user_pending ? 
        '<span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-700 border border-yellow-200"><i class="fas fa-clock mr-1"></i>Pending</span>' : '';

    const adminBadge = reply.is_admin ? 
        '<span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700 border border-red-200"><i class="fas fa-shield-alt mr-1"></i>Admin</span>' : '';

    replyDiv.innerHTML = `
        <div class="flex items-start space-x-3">
            <div class="flex-shrink-0">
                <div class="w-8 h-8 ${reply.is_admin ? 'bg-gradient-to-br from-red-400 to-red-500' : 'bg-gradient-to-br from-gray-400 to-gray-500'} rounded-full flex items-center justify-center text-white text-xs font-semibold shadow">
                    ${reply.is_admin ? '<i class="fas fa-shield-alt"></i>' : reply.display_name.charAt(0).toUpperCase()}
                </div>
            </div>
            <div class="flex-1 min-w-0">
                <div class="flex items-center space-x-2 mb-1">
                    <span class="text-sm font-semibold text-gray-900">${escapeHtml(reply.display_name)}</span>
                    ${adminBadge}
                    ${pendingBadge}
                    <span class="text-xs text-gray-500">${reply.time_ago}</span>
                </div>
                <div class="text-sm text-gray-700 leading-relaxed">${escapeHtml(reply.message).replace(/\n/g, '<br>')}</div>
            </div>
        </div>
    `;

    return replyDiv;
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

window.showFeedbackForm = function(projectId) {
    const modal = document.getElementById('feedbackModal');
    const form = document.getElementById('feedbackForm');
    const projectField = document.getElementById('projectId');
    const parentField = document.getElementById('parentCommentId');
    const modalTitle = document.getElementById('feedbackModalTitle');
    const submitBtn = document.getElementById('submitFeedbackBtn');

    if (modal && form && projectField && modalTitle) {
        projectField.value = projectId;
        if (parentField) parentField.value = '';
        modalTitle.textContent = 'Leave Feedback';
        if (submitBtn) {
            submitBtn.textContent = 'Submit Comment';
        }
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }
};

window.closeFeedbackModal = function() {
    const modal = document.getElementById('feedbackModal');
    const form = document.getElementById('feedbackForm');
    const submitBtn = document.getElementById('submitFeedbackBtn');

    if (modal) {
        modal.classList.add('hidden');
        document.body.style.overflow = 'auto';
    }

    if (form) {
        form.reset();
    }

    if (submitBtn) {
        submitBtn.textContent = 'Submit Comment';
    }
};

window.submitFeedback = function(event) {
    event.preventDefault();

    const form = document.getElementById('feedbackForm');
    const submitBtn = document.getElementById('submitFeedbackBtn');
    const originalText = submitBtn.textContent;

    // Disable submit button
    submitBtn.disabled = true;
    submitBtn.textContent = 'Submitting...';

    const formData = new FormData(form);

    // Determine the correct API URL
    const apiUrl = window.BASE_URL ? window.BASE_URL + 'api/feedback.php' : './api/feedback.php';

    fetch(apiUrl, {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.text();
    })
    .then(text => {
        try {
            const data = JSON.parse(text);
            if (data.success) {
                // Determine notification type based on status or message content
                let notificationType = 'success';
                if (data.status === 'pending' || data.message.includes('review')) {
                    notificationType = 'review';
                } else if (data.message.includes('language')) {
                    notificationType = 'language';
                } else if (data.status === 'approved') {
                    notificationType = 'success';
                }

                showStyledNotification(data.message, notificationType);
                closeFeedbackModal();

                // Reload comments section if it exists
                if (typeof loadComments === 'function') {
                    loadComments();
                } else {
                    // Refresh page after notification disappears if comment was approved
                    if (data.status === 'approved') {
                        setTimeout(() => {
                            window.location.reload();
                        }, 2000);
                    }
                }
            } else {
                showStyledNotification(data.message || 'Failed to submit comment', 'error');
            }
        } catch (parseError) {
            console.error('JSON Parse Error:', parseError);
            console.error('Response text:', text);
            showStyledNotification('Server error: Please try again later', 'error');
        }
    })
    .catch(error => {
        console.error('Error submitting comment:', error);
        showStyledNotification('Network error: Please check your connection and try again', 'error');
    })
    .finally(() => {
        // Re-enable submit button
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
    });
};

window.loadComments = function() {
    const projectId = document.getElementById('projectId')?.value;
    if (!projectId) return;

    fetch(`api/projects.php?action=comments&project_id=${projectId}`)
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const commentsContainer = document.getElementById('commentsContainer');
            if (commentsContainer) {
                commentsContainer.innerHTML = data.html || '';
            }
        }
    })
    .catch(error => {
        console.error('Error loading comments:', error);
    });
};

window.showProjectDetails = function(projectId) {
    // Navigate to project details page
    window.location.href = `projectDetails.php?id=${projectId}`;
};

// Enhanced notification system
function showEnhancedNotification(message, type = 'success', duration = 5000) {
    // Remove any existing notification
    const existingNotification = document.getElementById('enhancedNotification');
    if (existingNotification) {
        existingNotification.remove();
    }

    const notification = document.createElement('div');
    notification.id = 'enhancedNotification';

    let bgColor, textColor, borderColor, icon;
    switch (type) {
        case 'success':
        case 'approved':
            bgColor = 'bg-green-100';
            textColor = 'text-green-800';
            borderColor = 'border-green-300';
            icon = 'fa-check-circle text-green-600';
            break;
        case 'review':
        case 'pending':
            bgColor = 'bg-blue-100';
            textColor = 'text-blue-800';
            borderColor = 'border-blue-300';
            icon = 'fa-clock text-blue-600';
            break;
        case 'language':
        case 'blocked':
        case 'error':
        case 'rejected':
            bgColor = 'bg-red-100';
            textColor = 'text-red-800';
            borderColor = 'border-red-300';
            icon = 'fa-exclamation-circle text-red-600';
            break;
        default:
            bgColor = 'bg-gray-100';
            textColor = 'text-gray-800';
            borderColor = 'border-gray-300';
            icon = 'fa-info-circle text-gray-600';
            break;
    }

    notification.className = `fixed top-4 right-4 z-50 max-w-md w-full mx-4 ${bgColor} ${textColor} ${borderColor} border-2 rounded-lg shadow-lg transform transition-all duration-300 translate-x-full`;

    notification.innerHTML = `
        <div class="p-4">
            <div class="flex items-start">
                <div class="flex-shrink-0">
                    <i class="fas ${icon} text-lg"></i>
                </div>
                <div class="ml-3 flex-1">
                    <p class="text-sm font-medium leading-relaxed">${message}</p>
                </div>
                <div class="ml-4 flex-shrink-0">
                    <button onclick="this.closest('#enhancedNotification').remove()" class="inline-flex ${textColor} hover:opacity-75 focus:outline-none">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
        </div>
    `;

    document.body.appendChild(notification);

    // Animate in
    setTimeout(() => {
        notification.classList.remove('translate-x-full');
    }, 100);

    // Auto remove
    setTimeout(() => {
        notification.classList.add('translate-x-full');
        setTimeout(() => {
            if (notification.parentElement) {
                notification.remove();
            }
        }, 300);
    }, duration);
}

// Initialize comment form handlers
document.addEventListener('DOMContentLoaded', function() {
    // Initialize comment form
    const commentForm = document.getElementById('commentForm');
    if (commentForm) {
        commentForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            // Get form data
            const formData = new FormData(this);
            const submitBtn = document.getElementById('submitBtn');
            const responseMessage = document.getElementById('commentResponseMessage');

            // Validate required fields
            const name = formData.get('citizen_name');
            const message = formData.get('message');
            
            if (!name || !message) {
                showEnhancedNotification('Please fill in all required fields', 'error');
                return;
            }

            // Disable submit button during processing
            if (submitBtn) {
                submitBtn.disabled = true;
                const isReply = formData.get('parent_comment_id') !== '0';
                submitBtn.innerHTML = isReply ? 
                    '<i class="fas fa-spinner fa-spin mr-2"></i>Submitting Reply...' : 
                    '<i class="fas fa-spinner fa-spin mr-2"></i>Submitting Comment...';
            }

            try {
                // Determine the correct API URL
                const apiUrl = window.BASE_URL ? window.BASE_URL + 'api/feedback.php' : 'api/feedback.php';
                
                const response = await fetch(apiUrl, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                // Check if response is ok
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                // Get response text first to handle potential JSON parsing errors
                const responseText = await response.text();
                
                let data;
                try {
                    data = JSON.parse(responseText);
                } catch (parseError) {
                    console.error('JSON Parse Error:', parseError);
                    console.error('Response text:', responseText);
                    throw new Error('Invalid server response');
                }

                if (data.success) {
                    // Determine notification type based on status or message content
                    let notificationType = 'success';
                    if (data.status === 'pending' || data.message.toLowerCase().includes('review') || data.message.toLowerCase().includes('pending')) {
                        notificationType = 'pending';
                    } else if (data.message.toLowerCase().includes('language') || data.message.toLowerCase().includes('blocked')) {
                        notificationType = 'blocked';
                    } else if (data.status === 'approved' || data.message.toLowerCase().includes('approved') || data.message.toLowerCase().includes('successfully')) {
                        notificationType = 'approved';
                    }

                    // Show enhanced notification
                    showEnhancedNotification(data.message, notificationType);

                    // Also show in-page message for better UX
                    if (responseMessage) {
                        responseMessage.classList.remove('hidden', 'bg-red-100', 'bg-blue-100', 'bg-green-100', 'text-red-800', 'text-blue-800', 'text-green-800', 'border-red-200', 'border-blue-200', 'border-green-200');

                        if (notificationType === 'pending') {
                            responseMessage.classList.add('bg-blue-100', 'text-blue-800', 'border-blue-300');
                            responseMessage.innerHTML = '<i class="fas fa-clock mr-2"></i>' + data.message;
                        } else if (notificationType === 'blocked') {
                            responseMessage.classList.add('bg-red-100', 'text-red-800', 'border-red-300');
                            responseMessage.innerHTML = '<i class="fas fa-exclamation-circle mr-2"></i>' + data.message;
                        } else {
                            responseMessage.classList.add('bg-green-100', 'text-green-800', 'border-green-300');
                            responseMessage.innerHTML = '<i class="fas fa-check-circle mr-2"></i>' + data.message;
                        }

                        // Hide in-page message after delay
                        setTimeout(() => {
                            responseMessage.classList.add('hidden');
                        }, 6000);
                    }

                    // Reset form and reply state on success
                    this.reset();
                    if (typeof cancelReply === 'function') {
                        cancelReply();
                    }

                    // Reload page after notification time to show new comment if approved
                    if (notificationType === 'approved') {
                        setTimeout(() => {
                            window.location.reload();
                        }, 2000);
                    }
                } else {
                    // Show error notification
                    showEnhancedNotification(data.message || 'Failed to submit comment', 'error');

                    // Also show in-page error message
                    if (responseMessage) {
                        responseMessage.classList.remove('hidden', 'bg-blue-100', 'bg-green-100', 'text-blue-800', 'text-green-800', 'border-blue-200', 'border-green-200');
                        responseMessage.classList.add('bg-red-100', 'text-red-800', 'border-red-200');
                        responseMessage.innerHTML = '<i class="fas fa-exclamation-triangle mr-2"></i>' + (data.message || 'Failed to submit comment');

                        setTimeout(() => {
                            responseMessage.classList.add('hidden');
                        }, 6000);
                    }
                }
            } catch (error) {
                console.error('Error submitting comment:', error);

                let errorMessage = 'Network error: Please check your connection and try again';
                if (error.message === 'Invalid server response') {
                    errorMessage = 'Server error: Please try again later';
                } else if (error.message.includes('HTTP error')) {
                    errorMessage = 'Server connection failed: Please try again';
                }

                // Show error notification
                showEnhancedNotification(errorMessage, 'error');

                if (responseMessage) {
                    responseMessage.classList.remove('hidden', 'bg-blue-100', 'bg-green-100', 'text-blue-800', 'text-green-800', 'border-blue-200', 'border-green-200');
                    responseMessage.classList.add('bg-red-100', 'text-red-800', 'border-red-200');
                    responseMessage.innerHTML = '<i class="fas fa-exclamation-triangle mr-2"></i>' + errorMessage;

                    setTimeout(() => {
                        responseMessage.classList.add('hidden');
                    }, 6000);
                }
            } finally {
                // Re-enable submit button
                if (submitBtn) {
                    submitBtn.disabled = false;
                    const isReply = formData.get('parent_comment_id') !== '0';
                    submitBtn.innerHTML = isReply ? 
                        '<i class="fas fa-reply mr-2"></i>Submit Reply' : 
                        '<i class="fas fa-paper-plane mr-2"></i>Submit Comment';
                }
            }
        });
    }

    // Close modal when clicking outside
    const modal = document.getElementById('feedbackModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeFeedbackModal();
            }
        });
    }

    // Close modal with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeFeedbackModal();
        }
    });
});

// Clean up charts when page is being unloaded
window.addEventListener("beforeunload", () => {
    if (window.dashboardCharts) {
        window.dashboardCharts.destroyAllCharts();
    }
});