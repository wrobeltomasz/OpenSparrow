    <!-- Navigation sidebar -->
    <nav class="admin-nav" id="adminNav">
        <div class="nav-sections">

            <!-- OVERVIEW -->
            <div class="nav-section open">
                <div class="nav-section-items" style="padding-top:4px;">
                    <button class="admin-tab active" data-file="overview">
                        <img class="nav-item-icon" src="../assets/icons/health_and_safety.png" alt="">
                        Overview
                    </button>
                </div>
            </div>

            <!-- DATA MANAGEMENT -->
            <div class="nav-section">
                <div class="nav-section-header">
                    <img class="nav-section-icon" src="../assets/icons/data_table.png" alt="">
                    <span class="nav-section-label">Data Management</span>
                    <span class="nav-chevron">▼</span>
                </div>
                <div class="nav-section-items">
                    <button class="admin-tab" data-file="board">
                        <img class="nav-item-icon" src="../assets/icons/account_tree.png" alt="">
                        Board
                    </button>
                    <button class="admin-tab" data-file="calendar">
                        <img class="nav-item-icon" src="../assets/icons/manage_history.png" alt="">
                        Calendar
                    </button>
                    <button class="admin-tab" data-file="csv_import">
                        <img class="nav-item-icon" src="../assets/icons/upload.png" alt="">
                        CSV Import
                    </button>
                    <button class="admin-tab" data-file="dashboard">
                        <img class="nav-item-icon" src="../assets/icons/ballot.png" alt="">
                        Dashboard
                    </button>
                    <button class="admin-tab" data-file="etl">
                        <img class="nav-item-icon" src="../assets/icons/database.png" alt="">
                        ETL
                    </button>
                    <button class="admin-tab" data-file="files">
                        <img class="nav-item-icon" src="../assets/icons/upload.png" alt="">
                        Files
                    </button>
                    <button class="admin-tab" data-file="print">
                        <img class="nav-item-icon" src="../assets/icons/picture_as_pdf.png" alt="">
                        Printouts
                    </button>
                    <button class="admin-tab" data-file="schema">
                        <img class="nav-item-icon" src="../assets/icons/data_table.png" alt="">
                        Schema
                    </button>
                    <button class="admin-tab" data-file="user_records">
                        <img class="nav-item-icon" src="../assets/icons/id_card.png" alt="">
                        User Records
                    </button>
                    <button class="admin-tab" data-file="views">
                        <img class="nav-item-icon" src="../assets/icons/table_chart_view.png" alt="">
                        Views
                    </button>
                </div>
            </div>

            <!-- WORKFLOWS -->
            <div class="nav-section">
                <div class="nav-section-header">
                    <img class="nav-section-icon" src="../assets/icons/build.png" alt="">
                    <span class="nav-section-label">Workflows</span>
                    <span class="nav-chevron">▼</span>
                </div>
                <div class="nav-section-items">
                    <button class="admin-tab" data-file="automations">
                        <img class="nav-item-icon" src="../assets/icons/automation.png" alt="">
                        Automations
                    </button>
                    <button class="admin-tab" data-file="workflows">
                        <img class="nav-item-icon" src="../assets/icons/build.png" alt="">
                        Workflow Manager
                    </button>
                </div>
            </div>

            <!-- KNOWLEDGE BASE -->
            <div class="nav-section">
                <div class="nav-section-header">
                    <img class="nav-section-icon" src="../assets/icons/menu_book.png" alt="">
                    <span class="nav-section-label">Knowledge Base</span>
                    <span class="nav-chevron">▼</span>
                </div>
                <div class="nav-section-items">
                    <button class="admin-tab" data-file="rag">
                        <img class="nav-item-icon" src="../assets/icons/docs.png" alt="">
                        RAG Documents
                    </button>
                </div>
            </div>

            <!-- SYSTEM -->
            <div class="nav-section">
                <div class="nav-section-header">
                    <img class="nav-section-icon" src="../assets/icons/database.png" alt="">
                    <span class="nav-section-label">System</span>
                    <span class="nav-chevron">▼</span>
                </div>
                <div class="nav-section-items">
                    <button class="admin-tab" data-file="anonymization">
                        <img class="nav-item-icon" src="../assets/icons/fact_check.png" alt="">
                        Anonymization
                    </button>
                    <button class="admin-tab" data-file="backup">
                        <img class="nav-item-icon" src="../assets/icons/inventory.png" alt="">
                        Backup Tables
                    </button>
                    <button class="admin-tab" data-file="cron">
                        <img class="nav-item-icon" src="../assets/icons/manage_history.png" alt="">
                        Cron Notifications
                    </button>
                    <button class="admin-tab" data-file="demo">
                        <img class="nav-item-icon" src="../assets/icons/playground.png" alt="">
                        Demo Systems
                    </button>
                    <button class="admin-tab" data-file="health">
                        <img class="nav-item-icon" src="../assets/icons/health_and_safety.png" alt="">
                        Health Check
                    </button>
                    <button class="admin-tab" data-file="migrations">
                        <img class="nav-item-icon" src="../assets/icons/database.png" alt="">
                        Migrations
                    </button>
                    <button class="admin-tab" data-file="performance">
                        <img class="nav-item-icon" src="../assets/icons/health_and_safety.png" alt="">
                        Performance
                    </button>
                    <button class="admin-tab" data-file="settings">
                        <img class="nav-item-icon" src="../assets/icons/manage_history.png" alt="">
                        Settings
                    </button>
                    <button class="admin-tab" data-file="users">
                        <img class="nav-item-icon" src="../assets/icons/user_attributes.png" alt="">
                        Users
                    </button>
                </div>
            </div>

        </div><!-- /nav-sections -->
    </nav><!-- /admin-nav -->

    <!-- Left nav edge collapse tab -->
    <button class="nav-edge-toggle" id="navEdgeToggle" title="Toggle navigation" aria-label="Toggle navigation">&#8249;</button>

    <!-- Main content area -->
    <div class="admin-main">

        <!-- Breadcrumb -->
        <div class="admin-breadcrumb">
            <span class="breadcrumb-root">Admin</span>
            <span class="breadcrumb-sep">›</span>
            <span class="breadcrumb-current" id="breadcrumbCurrent">Schema</span>
        </div>

        <!-- Editor area: workspace -->
        <div class="admin-content">

            <section class="admin-workspace" id="workspace">
                <div id="itemPanel" class="admin-item-panel"></div>
                <div id="editorForm"></div>
            </section>

        </div>

    </div><!-- /admin-main -->
