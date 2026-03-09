<?php
// admin_sidebar.php
?>

<!DOCTYPE html>
<html lang="en">
  <head>
    <!-- Code by: www.codeinfoweb.com -->
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    
    <!-- Box icons CDN link -->
    <link
      href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css"
      rel="stylesheet"
    />
    
        <link rel="apple-touch-icon" sizes="180x180" href="/favicon_io/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon_io/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/favicon_io/favicon-16x16.png">
<link rel="manifest" href="/favicon_io/site.webmanifest">

    <style>
@import url("https://fonts.googleapis.com/css2?family=Agdasima:wght@400;700&display=swap");

* {
  margin: 0;
  padding: 0;
  font-family: "Agdasima", sans-serif;
  box-sizing: border-box;
}

/* Color Variables */
:root {
  --sidebar-bg: #2f323a;
  --sidebar-width: 100px;
  --sidebar-width-active: 200px;
  --text-color: #fff;
  --menu-item-color: rgb(188, 186, 186);
  --menu-item-hover-bg: rgb(117, 109, 109);
  --menu-item-hover-color: #fff;
  --menu-header-color: rgb(137, 135, 135);
  --tooltip-bg: rgba(0, 0, 0, 0.8);
  --border-color: rgb(218, 147, 147);
  --active-item-bg: rgb(71, 67, 67); /* Active item background */
}

body {
  display: flex;
  height: 100vh;
  overflow-x: hidden; /* Prevent horizontal scrolling */
}

.sidebar {
  position: fixed;
  top: 0;
  left: 0;
  height: 100%;
  width: var(--sidebar-width);
  color: var(--text-color);
  background-color: var(--sidebar-bg);
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  transition: width 1s ease; /* Smooth transition for sidebar width */
  z-index: 1000; /* Ensure sidebar is on top */
}

.logo,
.menu-item,
.logout {
  display: flex;
  align-items: center;
  justify-content: center;
  transition: justify-content 1s ease;
}

.logo {
  margin-top: 30px;
  align-items: center;
  transition: all 0.5s ease; /* Smooth transition for logo */
}

.logo i,
.menu-item i,
.logout i {
  font-size: 2rem;
  text-decoration: none;
  transition: 0.5s ease;
}

.logo span,
.menu-item span,
.logout span {
  margin-left: 10px;
  text-decoration: none;
  display: none;
  transition: 0.5s ease;
}

.menu {
  flex-grow: 1;
  display: flex;
  flex-direction: column;
  gap: 1rem;
  justify-content: flex-start;
  padding-top: 30px; /* Optional: adjust as needed */
}

.menu-header {
  color: var(--menu-header-color);
  text-transform: uppercase;
  text-align: center;
  font-size: 16px;
  transition: opacity 0.5s ease;
}

.menu-item {
  cursor: pointer;
  padding: 10px 20px;
  border-radius: 3px;
  color: var(--menu-item-color);
  transition: all 0.5s ease;
  white-space: nowrap; /* Prevent text wrapping */
  overflow: hidden; /* Hide overflowing text */
  text-overflow: ellipsis; /* Show ellipsis if text is too long */
}

.menu-item:hover,
.nav-active,
.logout:hover {
  background: var(--menu-item-hover-bg);
  color: var(--menu-item-hover-color);
  text-decoration: none;
  transition: 0.5s ease;
}

/* Active item style */
.menu-item.active {
  background: var(--active-item-bg);
  color: var(--menu-item-hover-color);
}

.menu-item i,
.logout i {
  font-size: 20px;
  text-decoration: none;
}

.logout {
  padding: 10px 20px;
  margin-bottom: 10px;
  border-radius: 3px;
  cursor: pointer;
  color: var(--menu-item-color);
}

.sidebar.active {
  width: var(--sidebar-width-active); /* Expand sidebar on active */
}

.sidebar.active .logo,
.sidebar.active .menu-item,
.sidebar.active .logout {
  justify-content: flex-start;
}

/* When sidebar is active show the nav items */
.sidebar.active .logo span,
.sidebar.active .menu-item span,
.sidebar.active .logout span {
  display: block;
}

.sidebar.active .menu-header {
  font-size: 20px;
  text-align: left;
  padding-left: 20px;
}

/* Toggle menu */
.toggle-menu {
  position: absolute;
  top: 10px;
  right: -20px;
  background-color: var(--sidebar-bg);
  border: 1px solid var(--menu-item-hover-bg);
  color: var(--text-color);
  padding: 10px 8px;
  border-radius: 5px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
}

/* Menu item tooltip */
[data-tooltip] {
  position: relative;
}

[data-tooltip]::before {
  content: attr(data-tooltip);
  position: absolute;
  left: 120%;
  top: 50%;
  transform: translateY(-50%);
  background-color: var(--tooltip-bg);
  padding: 5px 10px;
  border-radius: 5px;
  font-size: 20px;
  white-space: nowrap;
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.5s ease;
}

[data-tooltip]:after {
  content: "";
  position: absolute;
  left: 100%;
  top: 50%;
  transform: translateY(-50%);
  border-width: 5px;
  border-style: solid;
  border-color: transparent var(--tooltip-bg) transparent transparent;
  opacity: 0;
  transition: opacity 0.5s ease;
}

.sidebar:not(.active) [data-tooltip]:hover::before,
.sidebar:not(.active) [data-tooltip]:hover::after {
  opacity: 1;
}

.logout[data-tooltip]::before {
  left: 120%;
}

.logout[data-tooltip]::after {
  left: 100%;
}

.content {
  flex-grow: 1;
  margin-left: var(--sidebar-width); /* Ensure content doesn't overlap sidebar */
  transition: margin-left 1.5s ease;
  padding: 0; /* Space for content */
}


/* Adjust content when sidebar is active */
.sidebar.active + .content {
  margin-left: var(--sidebar-width-active);
}

.logo {
  display: flex;
  flex-direction: column; /* Stack icon and text vertically */
  align-items: center;     /* Center horizontally */
  justify-content: center; /* Center vertically (if needed) */
  text-align: center;
  padding-right: 10px;         /* Optional: adds spacing above/below */
  color: white;            /* Adjust based on your sidebar bg */
  transition: all 0.5s ease;  /* Smooth transition for logo */
}

.logo i {
  margin-bottom: 8px;      /* Space between icon and text */
  transition: 0.5s ease;
}

.logo span {
  transition: 0.5s ease;
}

.sidebar.active .logo span {
  display: block;
  margin-left: 10px;
}

.logo i {
  padding-left: 10px;
  font-size: 2rem;
  transition: 0.5s ease;
}

a.menu-item,
a.logout {
  text-decoration: none !important;
}

.menu {
  flex-grow: 1;
  display: flex;
  flex-direction: column;
  gap: 1rem;
  justify-content: flex-start; /* Adjust as needed */
  padding-top: 30px; /* Optional: adjust as needed */
}

.menu-item.active {
  background-color: #007bff; /* Highlight color */
  color: white; /* Change text color */
}
.logo span {
  opacity: 0;
  transition: opacity 0.5s ease;
  display: block; /* always block */
}

.sidebar.active .logo span {
  opacity: 1;
}
.menu-header {
  color: var(--menu-header-color);
  text-transform: uppercase;
  text-align: center;
  font-size: 16px;
  opacity: 0;
  transition: opacity 0.5s ease, font-size 0.5s ease, text-align 0.5s ease, padding-left 0.5s ease;
  display: block; /* always block to allow transition */
}
.sidebar.active .menu-header {
  font-size: 20px;
  text-align: left;
  padding-left: 20px;
  opacity: 1; /* fade in */
}

@media screen and (max-width: 700px) {
  body {
    flex-direction: column;
  }

  .sidebar {
    position: relative;
    width: 100%;
    height: auto;
    flex-direction: column;
    flex-wrap: nowrap;
    padding: 10px 0;
    align-items: flex-start;
  }

  .sidebar.active {
    width: 100%;
  }

  .logo {
    flex-direction: row;
    justify-content: flex-start;
    align-items: center;
    padding: 10px 20px;
    margin: 0;
    gap: 10px;
  }

  .logo span {
    display: block !important;
    opacity: 1 !important;
    font-size: 18px;
  }

  .menu {
    width: 100%;
    flex-direction: row;
    flex-wrap: wrap;
    justify-content: space-around;
    padding: 10px;
    gap: 10px;
  }

  .menu-header {
    display: none;
  }

  .menu-item {
    flex: 1 1 45%;
    display: flex;
    align-items: center;
    justify-content: flex-start;
    text-align: left;
    padding: 10px 15px;
    font-size: 14px;
    border-radius: 5px;
  }

  .menu-item span {
    margin-left: 10px;
    display: inline;
  }

  .logout {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    padding: 10px 15px;
    margin-top: 10px;
    width: 100%;
    font-size: 14px;
    border-top: 1px solid #444;
  }

  .logout span {
    margin-left: 10px;
    display: inline;
  }

  .toggle-menu {
    display: none;
  }

  .content {
    margin: 0;
    padding: 10px;
  }
}



</style>



    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
     <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  </head>


  <body>
    <div class="layout-wrapper">
<aside class="sidebar" id="sidebar">
  <!-- Toggle -->
  <div class="toggle-menu" onclick="toggleSidebar()">
    <i class="bx bx-menu"></i>
  </div>

  <!-- Logo -->
  <div class="logo">
    <i class="fa fa-hospital-o" style="font-size:24px"></i>
    <span>DialiEase: Admin Panel</span>
  </div>

  <!-- Menu -->
  <nav class="menu">
    <h1 class="menu-header">Menu</h1>

    <a href="../admin/dashboard.php" class="menu-item" id="dashboardLink" data-tooltip="Dashboard">
      <i class="bx bx-home-smile"></i>
      <span>Dashboard</span>
    </a>

    <a href="../admin/manage_schedules.php" class="menu-item" id="manageSchedulesLink" data-tooltip="Manage Schedules">
      <i class="bx bx-calendar"></i>
      <span>Scheduling</span>
    </a>

    <a href="../admin/manage_users.php" class="menu-item" id="manageUsersLink" data-tooltip="Manage Users">
      <i class="bx bx-user"></i>
      <span>User Management</span>
    </a>
    
    <a href="../admin/notifications.php" class="menu-item" id="notificationsLink" data-tooltip="Notifications">
  <i class="bx bx-bell"></i>
  <span>Notifications</span>
</a>

    <a href="../admin/logs.php" class="menu-item" id="logsLink" data-tooltip="System Logs">
  <i class="bx bx-file"></i>
  <span>Logs</span>
</a>

  </nav>

  <!-- Logout (Always last, bottom-aligned) -->
  <a href="../logout.php" class="logout" data-tooltip="Logout">
    <i class="bx bx-log-out"></i>
    <span>Logout</span>
  </a>
</aside>

<!-- Your main content will go here -->
<div class="content">
  <!-- Include topbar/header/content as needed -->
</div>

</div>


    <script>
    function toggleSidebar() {
    const sidebar = document.getElementById("sidebar");
    sidebar.classList.toggle("active");
  }
      const toggleButton = document.getElementById("toggle-button");
      const sidebar = document.getElementById("sidebar");

      const openIcon = toggleButton.querySelector(".bxs-right-arrow");
      const closeIcon = toggleButton.querySelector(".bxs-left-arrow");

      closeIcon.style.display = "none";

      toggleButton.addEventListener("click", () => {
        sidebar.classList.toggle("active");

        if (sidebar.classList.contains("active")) {
          openIcon.style.display = "none";
          closeIcon.style.display = "block";
        } else {
          openIcon.style.display = "block";
          closeIcon.style.display = "none";
        }
      });

      // Highlight active menu item based on the current page
      const currentPage = window.location.pathname.split("/").pop();

      const menuItems = document.querySelectorAll(".menu-item");

      menuItems.forEach(item => {
        const itemId = item.id.toLowerCase() + "Link";
        if (currentPage === itemId + ".php") {
          item.classList.add("active");
        }
      });
    </script>
    <?php if (isset($_SESSION['flash_message'])): ?>
  <script src="https://unpkg.com/sweetalert/dist/sweetalert.min.js"></script>
  <script>
    window.addEventListener('load', function () {
      swal({
        title: "<?= ucfirst($_SESSION['flash_type']) ?>",
        text: "<?= addslashes($_SESSION['flash_message']) ?>",
        icon: "<?= $_SESSION['flash_type'] ?>"
      });
    });
  </script>
  <?php 
    unset($_SESSION['flash_message']);
    unset($_SESSION['flash_type']);
  ?>
<?php endif; ?>

  </body>
</html>


