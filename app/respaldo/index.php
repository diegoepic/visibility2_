<?php
session_start();

// Verificar si el usuario ha iniciado sesión
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/con_.php';

// Obtener el nombre y apellido del usuario
$nombre = htmlspecialchars($_SESSION['usuario_nombre'], ENT_QUOTES, 'UTF-8');
$apellido = htmlspecialchars($_SESSION['usuario_apellido'], ENT_QUOTES, 'UTF-8');
$usuario = htmlspecialchars($_SESSION['usuario_usuario'], ENT_QUOTES, 'UTF-8');
$empresa = htmlspecialchars($_SESSION['usuario_empresa'], ENT_QUOTES, 'UTF-8');

// Obtener y sanitizar el ID del usuario
$usuario_id = $_SESSION['usuario_id'];
$usuario_id = intval($usuario_id);


?>

<!DOCTYPE html>
<!-- Template Name: Clip-One - Responsive Admin Template build with Twitter Bootstrap 3.x Version: 1.3 Author: ClipTheme -->
<!--[if IE 8]>
<html class="ie8 no-js" lang="en">
   <![endif]-->
   <!--[if IE 9]>
   <html class="ie9 no-js" lang="en">
      <![endif]-->
      <!--[if !IE]><!-->
      <html lang="en" class="no-js">
         <!--<![endif]-->
         <!-- start: HEAD -->
         <head>
            <title>Visibility 2</title>
            <!-- start: META -->
            <meta charset="utf-8" />
            <!--[if IE]>
            <meta http-equiv='X-UA-Compatible' content="IE=edge,IE=9,IE=8,chrome=1" />
            <![endif]-->
            <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimum-scale=1.0, maximum-scale=1.0">
            <meta name="apple-mobile-web-app-capable" content="yes">
            <meta name="apple-mobile-web-app-status-bar-style" content="black">
            <meta content="" name="description" />
            <meta content="" name="author" />
            <!-- end: META -->
            <!-- start: MAIN CSS -->
            <link rel="stylesheet" href="assets/plugins/bootstrap/css/bootstrap.min.css">
            <link rel="stylesheet" href="assets/plugins/font-awesome/css/font-awesome.min.css">
            <link rel="stylesheet" href="assets/fonts/style.css">
            <link rel="stylesheet" href="assets/css/main.css">
            <link rel="stylesheet" href="assets/css/main-responsive.css">
            <link rel="stylesheet" href="assets/plugins/iCheck/skins/all.css">
            <link rel="stylesheet" href="assets/plugins/bootstrap-colorpalette/css/bootstrap-colorpalette.css">
            <link rel="stylesheet" href="assets/plugins/perfect-scrollbar/src/perfect-scrollbar.css">
            <link rel="stylesheet" href="assets/css/theme_light.css" type="text/css" id="skin_color">
            <link rel="stylesheet" href="assets/css/print.css" type="text/css" media="print"/>
            <!--[if IE 7]>
            <link rel="stylesheet" href="assets/plugins/font-awesome/css/font-awesome-ie7.min.css">
            <![endif]-->
            <!-- end: MAIN CSS -->
            <!-- start: CSS REQUIRED FOR THIS PAGE ONLY -->
            <link rel="stylesheet" href="assets/plugins/fullcalendar/fullcalendar/fullcalendar.css">
            <!-- end: CSS REQUIRED FOR THIS PAGE ONLY -->
            <link rel="shortcut icon" href="favicon.ico" />
         </head>
         <!-- end: HEAD -->
         <!-- start: BODY -->
         <body>
            <style>
               .dropdown {
               position: relative;
               display: inline-block;
               }
               .dropdown-content {
               display: none;
               position: absolute;
               background-color: #f1f1f1;
               min-width: 160px;
               box-shadow: 0px 8px 16px rgba(0,0,0,0.2);
               z-index: 1;
               }
               .dropdown-content a {
               color: black;
               padding: 12px 16px;
               text-decoration: none;
               display: block;
               }
               .dropdown-content a:hover {
               background-color: #ddd;
               }
               .dropdown:hover .dropdown-content {
               display: block;
               }
               .dropdown:hover .dropbtn {
               background-color: #3e8e41;
               }
            </style>
            <!-- start: HEADER -->
            <div class="navbar navbar-inverse navbar-fixed-top">
               <!-- start: TOP NAVIGATION CONTAINER -->
               <div class="container">
                  <div class="navbar-header">
                     <!-- start: RESPONSIVE MENU TOGGLER -->
                     <button data-target=".navbar-collapse" data-toggle="collapse" class="navbar-toggle" type="button">
                     <span class="clip-list-2"></span>
                     </button>
                     <!-- end: RESPONSIVE MENU TOGGLER -->
                     <!-- start: LOGO -->
                     <a class="navbar-brand" href="#">
                     VISIBILITY 2
                     </a>
                     <!-- end: LOGO -->
                  </div>
                  <div class="navbar-tools">
                     <div class="nickname"><?php echo $nombre . ' ' . $apellido; ?></div>
                     
                     <ul class="nav navbar-right">
                        <!-- start: USER DROPDOWN -->
                        <li class="dropdown current-user">
                           <a data-toggle="dropdown" data-hover="dropdown" class="dropdown-toggle" data-close-others="true" href="#">
                           <i class="clip-chevron-down"></i>
                           </a>
                           <ul class="dropdown-menu">
                              <li>
                                 <a href="perfil.php">
                                 <i class="clip-user-2"></i>
                                 &nbsp;Perfil
                                 </a>
                              </li>
                              <li>
                                 <a href="logout.php">
                                 <i class="clip-exit"></i>
                                 &nbsp;Cerrar sesion
                                 </a>
                              </li>
                           </ul>
                        </li>
                        <!-- end: USER DROPDOWN -->
                     </ul>
                     <!-- end: TOP NAVIGATION MENU -->
                  </div>
               </div>
               <!-- end: TOP NAVIGATION CONTAINER -->
            </div>
            <!-- end: HEADER -->
            <!-- start: MAIN CONTAINER -->
            <div class="main-container">
               <div class="navbar-content">
                  <!-- start: SIDEBAR -->
                  <div class="main-navigation navbar-collapse collapse">
                     <!-- start: MAIN MENU TOGGLER BUTTON -->
                     <div class="navigation-toggler">
                        <i class="clip-chevron-left"></i>
                        <i class="clip-chevron-right"></i>
                     </div>
                     <!-- end: MAIN MENU TOGGLER BUTTON -->
                     <!-- start: MAIN NAVIGATION MENU -->
                     <ul class="main-navigation-menu">
                        <li class="active open">
                           <a href="index.html"><i class="clip-home-3"></i>
                           <span class="title"> Dashboard </span><span class="selected"></span>
                           </a>
                        </li>
                        <li>
                           <a href="javascript:void(0)"><i class="clip-screen"></i>
                           <span class="title"> Layouts </span><i class="icon-arrow"></i>
                           <span class="selected"></span>
                           </a>
                           <ul class="sub-menu">
                              <li>
                                 <a href="layouts_horizontal_menu1.html">
                                 <span class="title"> Horizontal Menu </span>
                                 <span class="badge badge-new">new</span>
                                 </a>
                              </li>
                              <li>
                                 <a href="layouts_sidebar_closed.html">
                                 <span class="title"> Sidebar Closed </span>
                                 </a>
                              </li>
                              <li>
                                 <a href="layouts_boxed_layout.html">
                                 <span class="title"> Boxed Layout </span>
                                 </a>
                              </li>
                              <li>
                                 <a href="layouts_footer_fixed.html">
                                 <span class="title"> Footer Fixed </span>
                                 </a>
                              </li>
                              <li>
                                 <a href="../clip-one-rtl/index.html">
                                 <span class="title"> RTL Version </span>
                                 </a>
                              </li>
                           </ul>
                        </li>
                        <li>
                           <a href="../../frontend/clip-one/index.html" target="_blank"><i class="clip-cursor"></i>
                           <span class="title"> Frontend Theme </span><span class="selected"></span>
                           </a>
                        </li>
                        <li>
                           <a href="javascript:void(0)"><i class="clip-cog-2"></i>
                           <span class="title"> UI Lab </span><i class="icon-arrow"></i>
                           <span class="selected"></span>
                           </a>
                           <ul class="sub-menu">
                              <li>
                                 <a href="ui_elements.html">
                                 <span class="title"> Elements </span>
                                 </a>
                              </li>
                              <li>
                                 <a href="ui_buttons.html">
                                 <span class="title"> Buttons &amp; icons </span>
                                 </a>
                              </li>
                              <li>
                                 <a href="ui_animations.html">
                                 <span class="title"> CSS3 Animation </span>
                                 </a>
                              </li>
                              <li>
                                 <a href="ui_modals.html">
                                 <span class="title"> Extended Modals </span>
                                 </a>
                              </li>
                              <li>
                                 <a href="ui_tabs_accordions.html">
                                 <span class="title"> Tabs &amp; Accordions </span>
                                 </a>
                              </li>
                              <li>
                                 <a href="ui_sliders.html">
                                 <span class="title"> Sliders </span>
                                 </a>
                              </li>
                              <li>
                                 <a href="ui_treeview.html">
                                 <span class="title"> Treeview </span>
                                 </a>
                              </li>
                              <li>
                                 <a href="ui_nestable.html">
                                 <span class="title"> Nestable List </span>
                                 </a>
                              </li>
                              <li>
                                 <a href="ui_typography.html">
                                 <span class="title"> Typography </span>
                                 </a>
                              </li>
                           </ul>
                        </li>
                        <li>
                           <a href="javascript:void(0)"><i class="clip-grid-6"></i>
                           <span class="title"> Tables </span><i class="icon-arrow"></i>
                           <span class="selected"></span>
                           </a>
                           <ul class="sub-menu">
                              <li>
                                 <a href="table_static.html">
                                 <span class="title">Static Tables</span>
                                 </a>
                              </li>
                              <li>
                                 <a href="table_responsive.html">
                                 <span class="title">Responsive Tables</span>
                                 </a>
                              </li>
                              <li>
                                 <a href="table_data.html">
                                 <span class="title">Data Tables</span>
                                 </a>
                              </li>
                           </ul>
                        </li>
                        <li>
                           <a href="javascript:void(0)"><i class="clip-pencil"></i>
                           <span class="title"> Forms </span><i class="icon-arrow"></i>
                           <span class="selected"></span>
                           </a>
                           <ul class="sub-menu">
                              <li>
                                 <a href="form_elements.html">
                                 <span class="title">Form Elements</span>
                                 </a>
                              </li>
                              <li>
                                 <a href="form_wizard.html">
                                 <span class="title">Form Wizard</span>
                                 </a>
                              </li>
                              <li>
                                 <a href="form_validation.html">
                                 <span class="title">Form Validation</span>
                                 </a>
                              </li>
                              <li>
                                 <a href="form_inline.html">
                                 <span class="title">Inline Editor</span>
                                 </a>
                              </li>
                              <li>
                                 <a href="form_x_editable.html">
                                 <span class="title">Form X-editable</span>
                                 </a>
                              </li>
                              <li>
                                 <a href="form_image_cropping.html">
                                 <span class="title">Image Cropping</span>
                                 </a>
                              </li>
                              <li>
                                 <a href="form_multiple_upload.html">
                                 <span class="title">Multiple File Upload</span>
                                 </a>
                              </li>
                              <li>
                                 <a href="form_dropzone.html">
                                 <span class="title">Dropzone File Upload</span>
                                 </a>
                              </li>
                           </ul>
                        </li>
                        <li>
                           <a href="javascript:void(0)"><i class="clip-user-2"></i>
                           <span class="title">Login</span><i class="icon-arrow"></i>
                           <span class="selected"></span>
                           </a>
                           <ul class="sub-menu">
                              <li>
                                 <a href="login_example1.html">
                                 <span class="title">Login Form Example 1</span>
                                 </a>
                              </li>
                              <li>
                                 <a href="login_example2.html">
                                 <span class="title">Login Form Example 2</span>
                                 </a>
                              </li>
                           </ul>
                        </li>
                        <li>
                           <a href="javascript:void(0)"><i class="clip-file"></i>
                           <span class="title">Pages</span><i class="icon-arrow"></i>
                           <span class="selected"></span>
                           </a>
                           <ul class="sub-menu">
                              <li>
                                 <a href="pages_user_profile.html">
                                 <span class="title">User Profile</span>
                                 </a>
                              </li>
                              <li>
                                 <a href="pages_invoice.html">
                                 <span class="title">Invoice</span>
                                 <span class="badge badge-new">new</span>
                                 </a>
                              </li>
                              <li>
                                 <a href="pages_gallery.html">
                                 <span class="title">Gallery</span>
                                 </a>
                              </li>
                              <li>
                                 <a href="pages_timeline.html">
                                 <span class="title">Timeline</span>
                                 </a>
                              </li>
                              <li>
                                 <a href="pages_calendar.html">
                                 <span class="title">Calendar</span>
                                 </a>
                              </li>
                              <li>
                                 <a href="pages_messages.html">
                                 <span class="title">Messages</span>
                                 </a>
                              </li>
                              <li>
                                 <a href="pages_blank_page.html">
                                 <span class="title">Blank Page</span>
                                 </a>
                              </li>
                           </ul>
                        </li>
                        <li>
                           <a href="javascript:void(0)"><i class="clip-attachment-2"></i>
                           <span class="title">Utility</span><i class="icon-arrow"></i>
                           <span class="selected"></span>
                           </a>
                           <ul class="sub-menu">
                              <li>
                                 <a href="utility_faq.html">
                                 <span class="title">Faq</span>
                                 </a>
                              </li>
                              <li>
                                 <a href="utility_search_result.html">
                                 <span class="title">Search Results </span>
                                 <span class="badge badge-new">new</span>
                                 </a>
                              </li>
                              <li>
                                 <a href="utility_lock_screen.html">
                                 <span class="title">Lock Screen</span>
                                 </a>
                              </li>
                              <li>
                                 <a href="utility_404_example1.html">
                                 <span class="title">Error 404 Example 1</span>
                                 </a>
                              </li>
                              <li>
                                 <a href="utility_404_example2.html">
                                 <span class="title">Error 404 Example 2</span>
                                 </a>
                              </li>
                              <li>
                                 <a href="utility_404_example3.html">
                                 <span class="title">Error 404 Example 3</span>
                                 </a>
                              </li>
                              <li>
                                 <a href="utility_500_example1.html">
                                 <span class="title">Error 500 Example 1</span>
                                 </a>
                              </li>
                              <li>
                                 <a href="utility_500_example2.html">
                                 <span class="title">Error 500 Example 2</span>
                                 </a>
                              </li>
                              <li>
                                 <a href="utility_pricing_table.html">
                                 <span class="title">Pricing Table</span>
                                 </a>
                              </li>
                              <li>
                                 <a href="utility_coming_soon.html">
                                 <span class="title">Cooming Soon</span>
                                 </a>
                              </li>
                           </ul>
                        </li>
                        <li>
                           <a href="javascript:;" class="active">
                           <i class="clip-folder"></i>
                           <span class="title"> 3 Level Menu </span>
                           <i class="icon-arrow"></i>
                           </a>
                           <ul class="sub-menu">
                              <li>
                                 <a href="javascript:;">
                                 Item 1 <i class="icon-arrow"></i>
                                 </a>
                                 <ul class="sub-menu">
                                    <li>
                                       <a href="#">
                                       Sample Link 1
                                       </a>
                                    </li>
                                    <li>
                                       <a href="#">
                                       Sample Link 2
                                       </a>
                                    </li>
                                    <li>
                                       <a href="#">
                                       Sample Link 3
                                       </a>
                                    </li>
                                 </ul>
                              </li>
                              <li>
                                 <a href="javascript:;">
                                 Item 1 <i class="icon-arrow"></i>
                                 </a>
                                 <ul class="sub-menu">
                                    <li>
                                       <a href="#">
                                       Sample Link 1
                                       </a>
                                    </li>
                                    <li>
                                       <a href="#">
                                       Sample Link 1
                                       </a>
                                    </li>
                                    <li>
                                       <a href="#">
                                       Sample Link 1
                                       </a>
                                    </li>
                                 </ul>
                              </li>
                              <li>
                                 <a href="#">
                                 Item 3
                                 </a>
                              </li>
                           </ul>
                        </li>
                        <li>
                           <a href="javascript:;">
                           <i class="clip-folder-open"></i>
                           <span class="title"> 4 Level Menu </span><i class="icon-arrow"></i>
                           <span class="arrow "></span>
                           </a>
                           <ul class="sub-menu">
                              <li>
                                 <a href="javascript:;">
                                 Item 1 <i class="icon-arrow"></i>
                                 </a>
                                 <ul class="sub-menu">
                                    <li>
                                       <a href="javascript:;">
                                       Sample Link 1 <i class="icon-arrow"></i>
                                       </a>
                                       <ul class="sub-menu">
                                          <li>
                                             <a href="#"><i class="fa fa-times"></i>
                                             Sample Link 1</a>
                                          </li>
                                          <li>
                                             <a href="#"><i class="fa fa-pencil"></i>
                                             Sample Link 1</a>
                                          </li>
                                          <li>
                                             <a href="#"><i class="fa fa-edit"></i>
                                             Sample Link 1</a>
                                          </li>
                                       </ul>
                                    </li>
                                    <li>
                                       <a href="#">
                                       Sample Link 1
                                       </a>
                                    </li>
                                    <li>
                                       <a href="#">
                                       Sample Link 2
                                       </a>
                                    </li>
                                    <li>
                                       <a href="#">
                                       Sample Link 3
                                       </a>
                                    </li>
                                 </ul>
                              </li>
                              <li>
                                 <a href="javascript:;">
                                 Item 2 <i class="icon-arrow"></i>
                                 </a>
                                 <ul class="sub-menu">
                                    <li>
                                       <a href="#">
                                       Sample Link 1
                                       </a>
                                    </li>
                                    <li>
                                       <a href="#">
                                       Sample Link 1
                                       </a>
                                    </li>
                                    <li>
                                       <a href="#">
                                       Sample Link 1
                                       </a>
                                    </li>
                                 </ul>
                              </li>
                              <li>
                                 <a href="#">
                                 Item 3
                                 </a>
                              </li>
                           </ul>
                        </li>
                        <li>
                           <a href="maps.html"><i class="clip-location"></i>
                           <span class="title">Maps</span>
                           <span class="selected"></span>
                           </a>
                        </li>
                        <li>
                           <a href="charts.html"><i class="clip-bars"></i>
                           <span class="title">Charts</span>
                           <span class="selected"></span>
                           </a>
                        </li>
                     </ul>
                     <!-- end: MAIN NAVIGATION MENU -->
                  </div>
                  <!-- end: SIDEBAR -->
               </div>
               <!-- start: PAGE -->
               <div class="main-content">
                  <!-- start: PANEL CONFIGURATION MODAL FORM -->
                  <div class="modal fade" id="panel-config" tabindex="-1" role="dialog" aria-hidden="true">
                     <div class="modal-dialog">
                        <div class="modal-content">
                           <div class="modal-header">
                              <button type="button" class="close" data-dismiss="modal" aria-hidden="true">
                              &times;
                              </button>
                              <h4 class="modal-title">Panel Configuration</h4>
                           </div>
                           <div class="modal-body">
                              Here will be a configuration form
                           </div>
                           <div class="modal-footer">
                              <button type="button" class="btn btn-default" data-dismiss="modal">
                              Close
                              </button>
                              <button type="button" class="btn btn-primary">
                              Save changes
                              </button>
                           </div>
                        </div>
                        <!-- /.modal-content -->
                     </div>
                     <!-- /.modal-dialog -->
                  </div>
                  <!-- /.modal -->
                  <!-- end: SPANEL CONFIGURATION MODAL FORM -->
                  <div class="container">
                     <!-- start: PAGE HEADER -->
                     <div class="row">
                        <div class="col-sm-12">
                           <!-- start: STYLE SELECTOR BOX -->
                           <div id="style_selector" class="hidden-xs" hidden>
                              <div id="style_selector_container" style="display:block">
                                 <div class="style-main-title">
                                    Style Selector
                                 </div>
                                 <div class="box-title">
                                    Choose Your Layout Style
                                 </div>
                                 <div class="input-box">
                                    <div class="input">
                                       <select name="layout">
                                          <option value="default">Wide</option>
                                          <option value="boxed">Boxed</option>
                                       </select>
                                    </div>
                                 </div>
                                 <div class="box-title">
                                    Choose Your Header Style
                                 </div>
                                 <div class="input-box">
                                    <div class="input">
                                       <select name="header">
                                          <option value="fixed">Fixed</option>
                                          <option value="default">Default</option>
                                       </select>
                                    </div>
                                 </div>
                                 <div class="box-title">
                                    Choose Your Footer Style
                                 </div>
                                 <div class="input-box">
                                    <div class="input">
                                       <select name="footer">
                                          <option value="default">Default</option>
                                          <option value="fixed">Fixed</option>
                                       </select>
                                    </div>
                                 </div>
                                 <div class="box-title">
                                    Backgrounds for Boxed Version
                                 </div>
                                 <div class="images boxed-patterns">
                                    <a id="bg_style_1" href="#"><img alt="" src="assets/images/bg.png"></a>
                                    <a id="bg_style_2" href="#"><img alt="" src="assets/images/bg_2.png"></a>
                                    <a id="bg_style_3" href="#"><img alt="" src="assets/images/bg_3.png"></a>
                                    <a id="bg_style_4" href="#"><img alt="" src="assets/images/bg_4.png"></a>
                                    <a id="bg_style_5" href="#"><img alt="" src="assets/images/bg_5.png"></a>
                                 </div>
                                 <div class="box-title">
                                    5 Predefined Color Schemes
                                 </div>
                                 <div class="images icons-color">
                                    <a id="light" href="#"><img class="active" alt="" src="assets/images/lightgrey.png"></a>
                                    <a id="dark" href="#"><img alt="" src="assets/images/darkgrey.png"></a>
                                    <a id="black_and_white" href="#"><img alt="" src="assets/images/blackandwhite.png"></a>
                                    <a id="navy" href="#"><img alt="" src="assets/images/navy.png"></a>
                                    <a id="green" href="#"><img alt="" src="assets/images/green.png"></a>
                                 </div>
                                 <div class="box-title">
                                    Style it with LESS
                                 </div>
                                 <div class="images">
                                    <div class="form-group">
                                       <label>
                                       Basic
                                       </label>
                                       <input type="text" value="#ffffff" class="color-base">
                                       <div class="dropdown">
                                          <a class="add-on dropdown-toggle" data-toggle="dropdown"><i style="background-color: #ffffff"></i></a>
                                          <ul class="dropdown-menu pull-right">
                                             <li>
                                                <div class="colorpalette"></div>
                                             </li>
                                          </ul>
                                       </div>
                                    </div>
                                    <div class="form-group">
                                       <label>
                                       Text
                                       </label>
                                       <input type="text" value="#555555" class="color-text">
                                       <div class="dropdown">
                                          <a class="add-on dropdown-toggle" data-toggle="dropdown"><i style="background-color: #555555"></i></a>
                                          <ul class="dropdown-menu pull-right">
                                             <li>
                                                <div class="colorpalette"></div>
                                             </li>
                                          </ul>
                                       </div>
                                    </div>
                                    <div class="form-group">
                                       <label>
                                       Elements
                                       </label>
                                       <input type="text" value="#007AFF" class="color-badge">
                                       <div class="dropdown">
                                          <a class="add-on dropdown-toggle" data-toggle="dropdown"><i style="background-color: #007AFF"></i></a>
                                          <ul class="dropdown-menu pull-right">
                                             <li>
                                                <div class="colorpalette"></div>
                                             </li>
                                          </ul>
                                       </div>
                                    </div>
                                 </div>
                                 <div style="height:25px;line-height:25px; text-align: center">
                                    <a class="clear_style" href="#">
                                    Clear Styles
                                    </a>
                                    <a class="save_style" href="#">
                                    Save Styles
                                    </a>
                                 </div>
                              </div>
                              <div class="style-toggle open"></div>
                           </div>
                           <!-- end: STYLE SELECTOR BOX -->
                           <!-- start: PAGE TITLE & BREADCRUMB -->
                           <div class="page-header">
                              <h1>Gestor de locales <small>campañas en curso &amp; estados </small></h1>
                           </div>
                           <!-- end: PAGE TITLE & BREADCRUMB -->
                        </div>
                     </div>

                     <div class="row">
                        <div class="col-sm-5">
                           <div class="panel panel-default">
                              <div class="panel-heading">
                                 <i class="clip-checkbox"></i>
                                 Campañas Programadas
                                 <div class="panel-tools">
                                    <a class="btn btn-xs btn-link panel-collapse expand" href="#">
                                    </a>
                                    <a class="btn btn-xs btn-link panel-refresh" href="#">
                                    <i class="fa fa-refresh"></i>
                                    </a>
                                 </div>
                              </div>
                              <div class="panel-body panel-scroll" style="height: 300px; display: none;">
                                 <ul class="todo">
                                    <li>
                                       <a class="todo-actions" href="javascript:void(0)">
                                       <i class="fa fa-square-o"></i>
                                       <span class="desc" style="opacity: 1; text-decoration: none;">W41 TOTTUS - SVELTY SEMI DESCREMADA (STOPPER VIBRIN MARCO PRECIO)</span>
                                       </a>
                                    </li>
                                    <li>
                                       <a class="todo-actions" href="javascript:void(0)">
                                       <i class="fa fa-square-o"></i>
                                       <span class="desc" style="opacity: 1; text-decoration: none;"> W41 TOTTUS - SVELTY SEMI DESCREMADA (FLEJERA EN L)</span>
                                       </a>
                                    </li>
                                    <li>
                                       <a class="todo-actions" href="javascript:void(0)">
                                       <i class="fa fa-square-o"></i>
                                       <span class="desc"> W41 UNIMARC - SVELTY SEMI DESCREMADA (STOPPER VIBRIN MARCO PRECIO)</span>
                                       </a>
                                    </li>
                                    <li>
                                       <a class="todo-actions" href="javascript:void(0)">
                                       <i class="fa fa-square-o"></i>
                                       <span class="desc">W36 SSRR - MILO INSTITUCIONAL (BANDEJA EN L)</span>
                                       </a>
                                    </li>
                                    <li>
                                       <a class="todo-actions" href="javascript:void(0)">
                                       <i class="fa fa-square-o"></i>
                                       <span class="desc"> W36 SISA - FLEJERAS MILO (BANDEJA EN L)</span>
                                       </a>
                                    </li>
                                    <li>
                                       <a class="todo-actions" href="javascript:void(0)">
                                       <i class="fa fa-square-o"></i>
                                       <span class="desc"> W36 UNIMARC - FLEJERAS MILO (BANDEJA EN L)</span>
                                       </a>
                                    </li>
                                    <li>
                                       <a class="todo-actions" href="javascript:void(0)">
                                       <i class="fa fa-square-o"></i>
                                       <span class="desc"> W36 JUMBO - FLEJERAS MILO (BANDEJA EN L)</span>
                                       </a>
                                    </li>
                                    <li>
                                       <a class="todo-actions" href="javascript:void(0)">
                                       <i class="fa fa-square-o"></i>
                                       <span class="desc"> W36 TOTTUS - FLEJERAS MILO (BANDEJA EN L)</span>
                                       </a>
                                    </li>
                                    <li>
                                       <a class="todo-actions" href="javascript:void(0)">
                                       <i class="fa fa-square-o"></i>
                                       <span class="desc" style="opacity: 1; text-decoration: none;">W36 TOTTUS - NIDO CHOCOLATE (MARCO PRECIO VIBRIN STOPPER)</span>
                                       </a>
                                    </li>
                                    <li>
                                       <a class="todo-actions" href="javascript:void(0)">
                                       <i class="fa fa-square-o"></i>
                                       <span class="desc" style="opacity: 1; text-decoration: none;"> W36 TOTTUS - NIDO CHOCOLATE (BANDEJA EN L)</span>
                                       </a>
                                    </li>
                                    <li>
                                       <a class="todo-actions" href="javascript:void(0)">
                                       <i class="fa fa-square-o"></i>
                                       <span class="desc"> W36 SSRR - MILO INSTITUCIONAL (PAYLOADER)</span>
                                       </a>
                                    </li>
                                 </ul>
                              </div>
                           </div>
                        </div>
                        
                        <div class="col-sm-7">
                           <div class="panel panel-default">
                              <div class="panel-heading">
                                 <i class="clip-users-2"></i>
                                 Locales Programados
                                 <div class="panel-tools">
                                    <a class="btn btn-xs btn-link panel-collapse collapses" href="#">
                                    </a>
                                    <a class="btn btn-xs btn-link panel-refresh" href="#">
                                    <i class="fa fa-refresh"></i>
                                    </a>
                                 </div>
                              </div>
                              
<!--Locales programados-->                              
                              <div class="panel-body panel-scroll" style="height:300px">
                                 <table class="table table-striped table-hover" id="sample-table-1">
                                    <thead>
                                       <tr>
                                          <th class="center">Cuenta</th>
                                          <th>Dirección</th>
                                          <th></th>
                                       </tr>
                                    </thead>
                <tbody>

                </tbody>
                                 </table>
                              </div>
                              
                              
                           </div>
                        </div>
                     </div>
                  </div>
               </div>
               <!-- end: PAGE -->
            </div>
            <!-- end: MAIN CONTAINER -->
            <!-- start: FOOTER -->
            <div class="footer clearfix">
               <div class="footer-inner">
                  2024 &copy; Visibility 2 por Mentecreativa.
               </div>
               <div class="footer-items">
                  <span class="go-top"><i class="clip-chevron-up"></i></span>
               </div>
            </div>
            <!-- end: FOOTER -->
            <div id="event-management" class="modal fade" tabindex="-1" data-width="760" style="display: none;">
               <div class="modal-dialog">
                  <div class="modal-content">
                     <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal" aria-hidden="true">
                        &times;
                        </button>
                        <h4 class="modal-title">Event Management</h4>
                     </div>
                     <div class="modal-body"></div>
                     <div class="modal-footer">
                        <button type="button" data-dismiss="modal" class="btn btn-light-grey">
                        Close
                        </button>
                        <button type="button" class="btn btn-danger remove-event no-display">
                        <i class='fa fa-trash-o'></i> Delete Event
                        </button>
                        <button type='submit' class='btn btn-success save-event'>
                        <i class='fa fa-check'></i> Save
                        </button>
                     </div>
                  </div>
               </div>
            </div>
            <div id="responsive" class="modal fade" tabindex="-1" data-width="760" style="display: none;">
               <div class="modal-header">
                  <button type="button" class="close" data-dismiss="modal" aria-hidden="true">
                  &times;
                  </button>
               </div>
               <div class="modal-body">
                  <div class="row">
                     <div class="col-md-6">
                        <div class="container mt-3">
                           <div class="row">
                              <div class="col-md-6">
                                 <div class="container mt-3">
                                 </div>
                              </div>
                           </div>
                        </div>
                        <div class="container mt-3">
                           <div class="row">
                              <div class="col-md-6">
                                 <h4 class="modal-title">Campañas</h4>
                                 <div class="container mt-3">
                                    <p style="margin: 0;">W36 JUMBO - FLEJERAS MILO (BANDEJA EN L)</p>
                                    <button class="btn btn-success btn-lg" data-toggle="modal" data-target="#myModal">
                                       Formulario
                                    </button>
                                 </div>
                                  <div class="container mt-3">
                                    <p style="margin: 0;">W36 JUMBO - FLEJERAS MILO (BANDEJA EN L)</p>
                                    <button class="btn btn-success btn-lg" data-toggle="modal" data-target="#myModal">
                                        Formulario 
                                    
                                    </button>
                                 </div>
                              </div>
                           </div>
                        </div>
                        <!-- Modal del formulario -->
                        <div class="modal fade" id="myModal" tabindex="-1" role="dialog" aria-labelledby="myModalLabel" aria-hidden="true">
                           <div class="modal-dialog" role="document">
                              <div class="modal-content">
                                 <div class="modal-header">
                                    <h5 class="modal-title" id="myModalLabel">Formulario de Preguntas</h5>
                                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                    <span aria-hidden="true">&times;</span>
                                    </button>
                                 </div>
                                 <div class="modal-body">
                                    <!-- Formulario dentro del modal -->
                                    <form id="mainForm">
                                       <div class="form-group">
                                          <label for="selectOption">Estado de actividad</label>
                                          <select class="form-control" id="selectOption">
                                             <option value="">Seleccione una opción</option>
                                             <option value="finalizado">Finalizado</option>
                                             <option value="pendiente">Pendiente</option>
                                          </select>
                                       </div>
                                    </form>
                                 </div>
                                 <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
                                    <button type="button" class="btn btn-primary" id="submitButton">Enviar</button>
                                 </div>
                              </div>
                           </div>
                        </div>
                        <!-- Modal para "Finalizado" -->
                        <div class="modal fade" id="finalizadoModal" tabindex="-1" role="dialog" aria-labelledby="finalizadoModalLabel" aria-hidden="true">
                           <div class="modal-dialog" role="document">
                              <div class="modal-content">
                                 <div class="modal-header">
                                    <h5 class="modal-title" id="finalizadoModalLabel">Implementación: Finalizado</h5>
                                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                    <span aria-hidden="true">&times;</span>
                                    </button>
                                 </div>
                                 <div class="modal-body">
                                    <form id="finalizadoForm">
                                       <div class="form-group">
                                          <label for="implementationDetails">Detalles de la Implementación</label>
                                          <textarea class="form-control" id="implementationDetails" rows="3" placeholder="Ingrese detalles importantes sobre la implementación"></textarea>
                                       </div>
                                       <div class="form-group">
                                          <label for="userQuestion">Ingrese Material</label>
                                          <input type="text" class="form-control" id="userQuestion" placeholder="Ingrese Material">
                                       </div>
                                       <div class="form-group">
                                          <label for="userQuestion">Ingrese Cantidad</label>
                                          <input type="text" class="form-control" id="userQuestion" placeholder="Ingrese Cantidad">
                                       </div>
                                       <div class="form-group">
                                          <label for="photoUpload">Subir Foto</label>
                                          <input type="file" class="form-control-file" id="photoUpload">
                                       </div>
                                    </form>
                                 </div>
                                 <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
                                    <button type="button" class="btn btn-primary" id="saveFinalizedData">Guardar</button>
                                 </div>
                              </div>
                           </div>
                        </div>
                        <div class="modal fade" id="pendienteModal" tabindex="-1" role="dialog" aria-labelledby="pendienteModalLabel" aria-hidden="true">
                           <div class="modal-dialog" role="document">
                              <div class="modal-content">
                                 <div class="modal-header">
                                    <h5 class="modal-title" id="pendienteModalLabel">Estado: Pendiente</h5>
                                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                    <span aria-hidden="true">&times;</span>
                                    </button>
                                 </div>
                                 <div class="modal-body">
                                    <p>La campaña ha sido marcado como "Pendiente".</p>
                                 </div>
                                 <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
                                 </div>
                              </div>
                           </div>
                        </div>
                        <div class="modal fade" id="myModal" tabindex="-1" role="dialog" aria-labelledby="myModalLabel" aria-hidden="true">
                           <div class="modal-dialog" role="document">
                              <div class="modal-content">
                                 <div class="modal-header">
                                    <h5 class="modal-title" id="myModalLabel">Formulario de Preguntas</h5>
                                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                    <span aria-hidden="true">&times;</span>
                                    </button>
                                 </div>
                                 <div class="modal-body">
                                    <!-- Formulario dentro del modal -->
                                    <form>
                                       <div class="form-group">
                                          <label for="selectOption">Estado de actividad</label>
                                          <select class="form-control" id="selectOption">
                                             <option value="">Seleccione una opción</option>
                                             <option value="opcion1">Finalizado</option>
                                             <option value="opcion2">Pendiente</option>
                                          </select>
                                       </div>
                                    </form>
                                 </div>
                                 <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
                                    <button type="button" class="btn btn-primary">Enviar</button>
                                 </div>
                              </div>
                           </div>
                        </div>
                     </div>
                     <div class="col-md-6">
                        <h4>Actividades Complementarias</h4>
                        <p>
                           REGISTRO DE ACTIVIDADES ADICIONALES	
                        </p>
                        REGISTRO DE VISITAS CD
                        </p>
                        <p>
                        </p>
                     </div>
                  </div>
               </div>
            </div>
            <!-- start: MAIN JAVASCRIPTS -->
            <!--[if lt IE 9]>
            <script src="assets/plugins/respond.min.js"></script>
            <script src="assets/plugins/excanvas.min.js"></script>
            <script type="text/javascript" src="https://ajax.googleapis.com/ajax/libs/jquery/1.10.2/jquery.min.js"></script>
            <![endif]-->
            <!--[if gte IE 9]><!-->
            <script src="https://ajax.googleapis.com/ajax/libs/jquery/2.0.3/jquery.min.js"></script>
            <!--<![endif]-->
            <script src="assets/plugins/jquery-ui/jquery-ui-1.10.2.custom.min.js"></script>
            <script src="assets/plugins/bootstrap/js/bootstrap.min.js"></script>
            <script src="assets/plugins/bootstrap-hover-dropdown/bootstrap-hover-dropdown.min.js"></script>
            <script src="assets/plugins/blockUI/jquery.blockUI.js"></script>
            <script src="assets/plugins/iCheck/jquery.icheck.min.js"></script>
            <script src="assets/plugins/perfect-scrollbar/src/jquery.mousewheel.js"></script>
            <script src="assets/plugins/perfect-scrollbar/src/perfect-scrollbar.js"></script>
            <script src="assets/plugins/less/less-1.5.0.min.js"></script>
            <script src="assets/plugins/jquery-cookie/jquery.cookie.js"></script>
            <script src="assets/plugins/bootstrap-colorpalette/js/bootstrap-colorpalette.js"></script>
            <script src="assets/js/main.js"></script>
            <!-- end: MAIN JAVASCRIPTS -->
            <!-- start: JAVASCRIPTS REQUIRED FOR THIS PAGE ONLY -->
            <script src="assets/plugins/flot/jquery.flot.js"></script>
            <script src="assets/plugins/flot/jquery.flot.pie.js"></script>
            <script src="assets/plugins/flot/jquery.flot.resize.min.js"></script>
            <script src="assets/plugins/jquery.sparkline/jquery.sparkline.js"></script>
            <script src="assets/plugins/jquery-easy-pie-chart/jquery.easy-pie-chart.js"></script>
            <script src="assets/plugins/jquery-ui-touch-punch/jquery.ui.touch-punch.min.js"></script>
            <script src="assets/plugins/fullcalendar/fullcalendar/fullcalendar.js"></script>
            <script src="assets/js/index.js"></script>
            <!-- end: JAVASCRIPTS REQUIRED FOR THIS PAGE ONLY -->
            <script>
               jQuery(document).ready(function() {
               	Main.init();
               	Index.init();
               });
            </script>
            <script>
               document.getElementById('submitButton').addEventListener('click', function() {
                   var selectedOption = document.getElementById('selectOption').value;
                   if (selectedOption === 'finalizado') {
                       $('#myModal').modal('hide');
                       $('#finalizadoModal').modal('show');
                   } else if (selectedOption === 'pendiente') {
                       $('#myModal').modal('hide');
                       $('#pendienteModal').modal('show');
                   } else {
                       alert('Por favor, seleccione una opción.');
                   }
               });
            </script>
         </body>
         <!-- end: BODY -->
      </html>