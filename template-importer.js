/**
 * Template Importer JavaScript
 * Handles the UI interactions and AJAX calls for template importing
 */
(function ($) {
  "use strict";

  // Variables
  var ajaxUrl = csmfTemplateImporter.ajaxUrl;
  var nonce = csmfTemplateImporter.nonce;
  var templatesLoaded = false;
  var templates = {};
  var i18n = csmfTemplateImporter.i18n || {};

  /**
   * Initialize the template importer
   */
  function init() {
    // Tab Navigation
    setupTabNavigation();

    // Load templates if on templates tab
    if ($("#remote-templates-tab").is(":visible")) {
      loadTemplates();
    }

    // Setup form handlers
    setupFormHandlers();

    // Setup search and filtering
    setupSearch();

    // Add refresh button handler
    $(".csmf-refresh-button").on("click", function () {
      templatesLoaded = false;
      loadTemplates();
    });

    // Add back to top button
    addBackToTopButton();

    $(document).on("click", ".csmf-convert-template", handleConvertButtonClick);

    // Add event listeners for popup actions
    $(document).on("click", ".csmf-popup-close", closePopup);
    $(document).on("click", ".csmf-popup-overlay", handleOverlayClick);

    // Handle ESC key to close popup
    $(document).on("keydown", function (e) {
      if (e.key === "Escape" && $(".csmf-popup-overlay").length > 0) {
        closePopup();
      }
    });

    // Set a delayed check for Elementor
    setTimeout(function () {
      if (typeof elementor !== "undefined") {
        console.log("Elementor found, adding integration");
        addTemplateLibraryIntegration();
      } else {
        console.log("Elementor not available, setting up MutationObserver");
        setupElementorWatcher();
      }
    }, 1000);
  }

  /**
   * Close all popups
   */
  function closePopup() {
    $(".csmf-popup-overlay").remove();
  }

  /**
   * Handle clicks on popup overlay
   */
  function handleOverlayClick(e) {
    if ($(e.target).hasClass("csmf-popup-overlay")) {
      closePopup();
    }
  }

  /**
   * Set up a watcher to detect when Elementor becomes available
   */
  function setupElementorWatcher() {
    // Check if we're on an admin page that might load Elementor
    if (
      window.location.href.indexOf("post.php") > -1 ||
      window.location.href.indexOf("post-new.php") > -1 ||
      window.location.href.indexOf("edit.php") > -1
    ) {
      console.log("Setting up Elementor watcher");

      // Set an interval to check for Elementor
      var checkElementor = setInterval(function () {
        if (typeof elementor !== "undefined") {
          console.log("Elementor detected by watcher");
          clearInterval(checkElementor);
          addTemplateLibraryIntegration();
        }
      }, 1000);

      // Clear after 60 seconds to avoid endless checking
      setTimeout(function () {
        clearInterval(checkElementor);
      }, 60000);
    }
  }

  /**
   * Setup tab navigation
   */
  function setupTabNavigation() {
    $(".nav-tab").on("click", function (e) {
      e.preventDefault();

      // Update active tab
      $(".nav-tab").removeClass("nav-tab-active");
      $(this).addClass("nav-tab-active");

      // Hide all tab contents
      $(".csmf-tab-content").hide();

      // Show selected tab content
      var targetId = $(this).attr("href") + "-tab";
      $(targetId).show();

      // Load templates if needed
      if (targetId === "#remote-templates-tab" && !templatesLoaded) {
        loadTemplates();
      }
    });
  }

  /**
   * Load templates via AJAX
   */
  function loadTemplates() {
    $(".csmf-templates-loader").show();
    $(".csmf-templates-grid").hide();
    $(".csmf-filter-container").empty();

    $.ajax({
      url: ajaxUrl,
      type: "POST",
      data: {
        action: "csmf_fetch_templates",
        nonce: nonce,
      },
      success: function (response) {
        $(".csmf-templates-loader").hide();

        if (response.success && response.data && response.data.templates) {
          templates = response.data.templates;
          templatesLoaded = true;
          renderTemplates(templates);
          addCategoryFilter(templates);
        } else {
          showError(response.data ? response.data.message : i18n.noTemplatesFound || "Failed to load templates");
        }
      },
      error: function (xhr, status, error) {
        $(".csmf-templates-loader").hide();
        showError("Error loading templates: " + error);
      },
    });
  }

  /**
   * Render templates in the grid
   *
   * @param {Object} templates Templates object
   */
  function renderTemplates(templates) {
    var $grid = $(".csmf-templates-grid");
    $grid.empty();

    if (!templates || Object.keys(templates).length === 0) {
      $grid.append(
        '<div class="csmf-notice warning">' + (i18n.noTemplatesFound || "No templates available.") + "</div>"
      );
      $grid.show();
      return;
    }

    // Add each template to the grid
    $.each(templates, function (id, template) {
      if (typeof template !== "object") {
        return;
      }

      var templateId = template.id || id;
      var title = template.title || "Unknown Template";
      var thumbnail = template.thumbnail || "";
      var category = template.category || "";
      var pageImportUrl = template.page_import_url || "";
      var templateImportUrl = template.template_import_url || "";
      var previewUrl = template.preview_url || thumbnail;

      var $card = $('<div class="csmf-template-card"></div>');

      // Add category as data attribute
      if (category) {
        $card.attr("data-category", category);
      }

      // Template preview
      var $preview = $('<div class="csmf-template-preview"></div>');
      if (thumbnail) {
        $preview.append('<img src="' + thumbnail + '" alt="' + title + '" />');
      } else {
        $preview.append('<div class="csmf-no-preview"><span class="dashicons dashicons-format-image"></span></div>');
      }

      // Add category badge
      if (category) {
        $preview.append('<span class="csmf-category-badge">' + category + "</span>");
      }

      // Add preview button if preview URL is available
      if (previewUrl) {
        var $previewBtn = $(
          '<a href="' +
            previewUrl +
            '" target="_blank" class="csmf-preview-button" title="' +
            (i18n.previewTemplate || "Preview Template") +
            '">' +
            '<span class="dashicons dashicons-visibility"></span>' +
            "</a>"
        );
        $preview.append($previewBtn);
      }

      $card.append($preview);

      // Template info
      var $info = $('<div class="csmf-template-info"></div>');
      $info.append("<h3>" + title + "</h3>");

      // Template actions
      var $actions = $('<div class="csmf-template-actions"></div>');

      // Import as Page button
      var $pageBtn = $(
        '<button type="button" class="button csmf-import-btn" data-type="page" data-id="' +
          templateId +
          '" data-url="' +
          pageImportUrl +
          '" data-title="' +
          title +
          '">' +
          (i18n.importAsPage || "Import as Page") +
          "</button>"
      );
      $actions.append($pageBtn);

      // Import as Template button
      var $templateBtn = $(
        '<button type="button" class="button button-primary csmf-import-btn" data-type="template" data-id="' +
          templateId +
          '" data-url="' +
          templateImportUrl +
          '" data-title="' +
          title +
          '">' +
          (i18n.importAsTemplate || "Import as Template") +
          "</button>"
      );
      $actions.append($templateBtn);

      $info.append($actions);
      $card.append($info);

      $grid.append($card);
    });

    $grid.show();

    // Set up click handlers for the newly created buttons
    $(".csmf-import-btn").on("click", handleTemplateImport);
  }

  /**
   * Handle template import
   */
  function handleTemplateImport() {
    var $button = $(this);
    var originalText = $button.text();
    var templateId = $button.data("id");
    var templateUrl = $button.data("url");
    var templateTitle = $button.data("title");
    var importType = $button.data("type");

    var ajaxUrl = csmfTemplateImporter.ajaxUrl;
    var nonce = csmfTemplateImporter.nonce;
    var i18n = csmfTemplateImporter.i18n || {};

    // Show loading overlay
    var $loadingOverlay = createLoadingOverlay();
    $("body").append($loadingOverlay);

    $.ajax({
      url: ajaxUrl,
      type: "POST",
      data: {
        action: "csmf_import_template",
        template_id: templateId,
        import_type: importType,
        template_title: templateTitle,
        nonce: nonce,
      },
      success: function (response) {
        console.log(response);
        // Remove loading overlay
        $loadingOverlay.remove();

        if (response.success) {
          // Show success popup instead of redirecting
          showImportSuccessPopup(response.data, importType);
        } else {
          // Show error and reset button
          $button.prop("disabled", false).text(originalText);
          var errorMsg = response.data ? response.data.message : "Import failed";
          showError(errorMsg, $button.closest(".csmf-template-card"));
        }
      },
      error: function (xhr, status, error) {
        console.log(xhr, status, error);
        // Remove loading overlay
        $loadingOverlay.remove();

        // Show error and reset button
        $button.prop("disabled", false).text(originalText);
        showError("Error importing template: " + error, $button.closest(".csmf-template-card"));
      },
    });
  }

  /**
   * Create loading overlay
   */
  function createLoadingOverlay() {
    var i18n = csmfTemplateImporter.i18n || {};
    return $(
      '<div class="csmf-loading-overlay">' +
        '<div class="csmf-loading-spinner">' +
        '<div class="csmf-loading-bounce1"></div>' +
        '<div class="csmf-loading-bounce2"></div>' +
        '<div class="csmf-loading-bounce3"></div>' +
        "</div>" +
        '<div class="csmf-loading-text">' +
        (i18n.importing || "Importing...") +
        "</div>" +
        "</div>"
    );
  }

  /**
   * Show import success popup
   */
  function showImportSuccessPopup(data, importType) {
    var i18n = csmfTemplateImporter.i18n || {};
    // Format post type for display
    var postTypeText = importType === "page" ? i18n.pageText || "Page" : i18n.templateText || "Template";

    // Create popup HTML
    var popup = $(
      '<div class="csmf-popup-overlay">' +
        '<div class="csmf-popup-container">' +
        '<div class="csmf-popup-header">' +
        "<h3>" +
        (i18n.success || "Template Imported Successfully") +
        "</h3>" +
        '<button class="csmf-popup-close">&times;</button>' +
        "</div>" +
        '<div class="csmf-popup-body">' +
        "<p>" +
        (data.message || "The template was successfully imported as a " + postTypeText + ".") +
        "</p>" +
        '<div class="csmf-popup-buttons">' +
        '<a href="' +
        (data.preview_url || window.location.origin + "?p=" + data.template_id) +
        '" target="_blank" class="button">' +
        '<span class="dashicons dashicons-visibility"></span> ' +
        (i18n.preview || "Preview") +
        "</a>" +
        '<a href="' +
        data.edit_url +
        '" class="button button-primary">' +
        '<span class="dashicons dashicons-edit"></span> ' +
        (i18n.edit || "Edit with Elementor") +
        "</a>" +
        (importType === "template"
          ? '<button class="button button-secondary csmf-convert-btn" data-template-id="' +
            data.template_id +
            '">' +
            '<span class="dashicons dashicons-download"></span> ' +
            (i18n.convertToPage || "Convert to Page") +
            "</button>"
          : "") +
        "</div>" +
        "</div>" +
        "</div>" +
        "</div>"
    );

    // Add popup to body
    $("body").append(popup);

    // Add conversion button handler
    popup.find(".csmf-convert-btn").on("click", function () {
      var templateId = $(this).data("template-id");
      convertTemplateToPage(popup, templateId);
    });

    // Add close handlers
    setupPopupCloseHandlers(popup);
  }

  /**
   * Convert template to page
   */
  function convertTemplateToPage(popup, templateId) {
    $.ajax({
      url: ajaxUrl,
      type: "POST",
      data: {
        action: "csmf_convert_template_to_page",
        template_id: templateId,
        nonce: nonce,
      },
      beforeSend: function () {
        // Show loading in the popup
        popup.find(".csmf-popup-body").html("<p>" + (i18n.converting || "Converting template to page...") + "</p>");
      },
      success: function (response) {
        if (response.success) {
          // Update popup content
          popup
            .find(".csmf-popup-body")
            .html(
              "<p>" +
                (response.data.message || "Template successfully converted to page") +
                "</p>" +
                '<div class="csmf-popup-buttons">' +
                '<a href="' +
                response.data.preview_url +
                '" target="_blank" class="button">' +
                '<span class="dashicons dashicons-visibility"></span> ' +
                (i18n.previewPage || "Preview Page") +
                "</a>" +
                '<a href="' +
                response.data.edit_url +
                '" class="button button-primary">' +
                '<span class="dashicons dashicons-edit"></span> ' +
                (i18n.editPage || "Edit Page") +
                "</a>" +
                "</div>"
            );
        } else {
          popup
            .find(".csmf-popup-body")
            .html(
              '<p class="csmf-error">' +
                (response.data.message || "Error converting template") +
                "</p>" +
                '<div class="csmf-popup-buttons">' +
                '<button class="button csmf-popup-close">' +
                (i18n.close || "Close") +
                "</button>" +
                "</div>"
            );
        }
      },
      error: function () {
        popup
          .find(".csmf-popup-body")
          .html(
            '<p class="csmf-error">' +
              (i18n.errorServer || "Error connecting to the server") +
              "</p>" +
              '<div class="csmf-popup-buttons">' +
              '<button class="button csmf-popup-close">' +
              (i18n.close || "Close") +
              "</button>" +
              "</div>"
          );
      },
    });
  }

  /**
   * Setup popup close handlers
   */
  function setupPopupCloseHandlers(popup) {
    // Close button
    popup.find(".csmf-popup-close").on("click", function () {
      popup.remove();
    });

    // Close on outside click
    popup.on("click", function (e) {
      if ($(e.target).is(".csmf-popup-overlay")) {
        popup.remove();
      }
    });
  }

  /**
   * Setup form handlers
   */
  function setupFormHandlers() {
    // File input name display
    $("#csmf-template-file").on("change", function () {
      var fileName = $(this).val().split("\\").pop();
      if (fileName) {
        $(".csmf-file-name").text(fileName);
      } else {
        $(".csmf-file-name").text(i18n.noFileSelected || "No file selected");
      }
    });

    // Handle file upload form submission
    $("#csmf-template-upload-form").on("submit", function (e) {
      e.preventDefault();

      var $form = $(this);
      var $submitBtn = $form.find(".csmf-upload-button");
      var $progressBar = $form.find(".csmf-upload-progress");
      var $progressInner = $progressBar.find(".csmf-progress-bar-inner");
      var $progressStatus = $progressBar.find(".csmf-progress-status");
      var $response = $form.find(".csmf-upload-response");

      // Check if file is selected
      var fileInput = $form.find('input[type="file"]')[0];
      if (!fileInput.files.length) {
        showError("Please select a file to upload", $response);
        return;
      }

      // Prepare form data
      var formData = new FormData($form[0]);
      formData.append("action", "csmf_upload_template");
      formData.append("nonce", nonce);

      // Show progress bar and disable submit button
      $submitBtn.prop("disabled", true);
      $progressBar.show();
      $response.empty();

      $.ajax({
        url: ajaxUrl,
        type: "POST",
        data: formData,
        contentType: false,
        processData: false,
        xhr: function () {
          var xhr = new window.XMLHttpRequest();

          // Upload progress
          xhr.upload.addEventListener(
            "progress",
            function (e) {
              if (e.lengthComputable) {
                var percent = Math.round((e.loaded / e.total) * 100);
                $progressInner.css("width", percent + "%");
                $progressStatus.text(percent + "%");
              }
            },
            false
          );

          return xhr;
        },
        success: function (response) {
          // Show 100% complete
          $progressInner.css("width", "100%");
          $progressStatus.text("100%");

          setTimeout(function () {
            $progressBar.hide();
            $submitBtn.prop("disabled", false);

            if (response.success) {
              // Show success message
              $response.html(
                '<div class="csmf-success">' + (response.data.message || "Template imported successfully") + "</div>"
              );

              // Reset form
              $form[0].reset();
              $(".csmf-file-name").text(i18n.noFileSelected || "No file selected");

              // Redirect to the imported template
              setTimeout(function () {
                if (response.data && response.data.edit_url) {
                  window.location.href = response.data.edit_url;
                }
              }, 1500);
            } else {
              // Show error
              showError(response.data ? response.data.message : "Upload failed", $response);
            }
          }, 500);
        },
        error: function (xhr, status, error) {
          // Hide progress and enable button
          $progressBar.hide();
          $submitBtn.prop("disabled", false);

          // Show error
          showError("Error uploading template: " + error, $response);
        },
      });
    });
  }

  /**
   * Setup search functionality
   */
  function setupSearch() {
    $(".csmf-search-input").on("keyup", function () {
      var searchTerm = $(this).val().toLowerCase();

      $(".csmf-template-card").each(function () {
        var $card = $(this);
        var title = $card.find("h3").text().toLowerCase();
        var category = $card.attr("data-category") ? $card.attr("data-category").toLowerCase() : "";

        if (title.indexOf(searchTerm) > -1 || category.indexOf(searchTerm) > -1) {
          $card.show();
        } else {
          $card.hide();
        }
      });

      // Show no results message
      if ($(".csmf-template-card:visible").length === 0 && searchTerm !== "") {
        if ($(".csmf-no-results").length === 0) {
          $(".csmf-templates-grid").append(
            '<div class="csmf-no-results">No templates found matching "' + searchTerm + '"</div>'
          );
        }
      } else {
        $(".csmf-no-results").remove();
      }
    });
  }

  /**
   * Add category filter
   *
   * @param {Object} templates Templates object
   */
  function addCategoryFilter(templates) {
    // Check if templates have categories
    var hasCategories = false;
    var categories = {};

    $.each(templates, function (id, template) {
      if (template.category && template.category.trim() !== "") {
        hasCategories = true;
        categories[template.category] = true;
      }
    });

    if (hasCategories) {
      var $filterContainer = $(".csmf-filter-container");
      var $filterLabel = $('<span class="csmf-filter-label">' + (i18n.filterBy || "Filter by:") + " </span>");
      var $filterSelect = $('<select class="csmf-category-filter"></select>');

      // Add "All" option
      $filterSelect.append('<option value="all">' + (i18n.allCategories || "All Categories") + "</option>");

      // Add category options
      $.each(Object.keys(categories).sort(), function (i, category) {
        $filterSelect.append('<option value="' + category + '">' + category + "</option>");
      });

      $filterContainer.append($filterLabel).append($filterSelect);

      // Handle category filtering
      $filterSelect.on("change", function () {
        var selectedCategory = $(this).val();

        $(".csmf-template-card").each(function () {
          var $card = $(this);
          var category = $card.attr("data-category") || "";

          if (selectedCategory === "all" || category === selectedCategory) {
            $card.show();
          } else {
            $card.hide();
          }
        });

        // Show no results message
        if ($(".csmf-template-card:visible").length === 0) {
          if ($(".csmf-no-results").length === 0) {
            $(".csmf-templates-grid").append(
              '<div class="csmf-no-results">No templates found in category "' + selectedCategory + '"</div>'
            );
          }
        } else {
          $(".csmf-no-results").remove();
        }
      });
    }
  }

  /**
   * Show error message
   *
   * @param {string} message Error message
   * @param {Object} $container Container to append the error to (optional)
   */
  function showError(message, $container) {
    var $error = $('<div class="csmf-error"></div>').text(message);

    if (!$container) {
      $container = $(".csmf-templates-grid");
      $container.show();
    }

    if ($container.hasClass("csmf-upload-response")) {
      $container.empty().append($error);
    } else {
      $container.append($error);
    }

    // Scroll to error
    $("html, body").animate(
      {
        scrollTop: $error.offset().top - 100,
      },
      500
    );

    // Remove error after 5 seconds
    setTimeout(function () {
      $error.fadeOut(function () {
        $(this).remove();
      });
    }, 5000);
  }

  /**
   * Add back to top button
   */
  function addBackToTopButton() {
    var $backToTop = $(
      '<a href="#" class="csmf-back-to-top" style="display:none;"><span class="dashicons dashicons-arrow-up-alt2"></span></a>'
    );
    $("body").append($backToTop);

    $(window).on("scroll", function () {
      if ($(this).scrollTop() > 300) {
        $backToTop.fadeIn();
      } else {
        $backToTop.fadeOut();
      }
    });

    $backToTop.on("click", function (e) {
      e.preventDefault();
      $("html, body").animate({ scrollTop: 0 }, 500);
    });
  }

  /**
   * Initialize tooltips
   */
  function initTooltips() {
    $(document).on("mouseenter", "[data-tooltip]", function () {
      var $el = $(this);
      var tooltipText = $el.attr("data-tooltip");

      var $tooltip = $('<div class="csmf-tooltip"></div>').text(tooltipText);
      $("body").append($tooltip);

      var elOffset = $el.offset();
      $tooltip.css({
        top: elOffset.top - $tooltip.outerHeight() - 10,
        left: elOffset.left + $el.outerWidth() / 2 - $tooltip.outerWidth() / 2,
      });

      setTimeout(function () {
        $tooltip.addClass("csmf-tooltip-visible");
      }, 10);
    });

    $(document).on("mouseleave", "[data-tooltip]", function () {
      $(".csmf-tooltip").removeClass("csmf-tooltip-visible").remove();
    });
  }

  /**
   * Format date string
   *
   * @param {string} dateString Date string
   * @return {string} Formatted date
   */
  function formatDate(dateString) {
    if (!dateString) return "";

    var date = new Date(dateString);
    if (isNaN(date.getTime())) return dateString;

    return date.toLocaleDateString();
  }

  /**
   * Add preview modal functionality
   */
  function setupPreviewModal() {
    // Create modal container if it doesn't exist
    if ($("#csmf-preview-modal").length === 0) {
      var $modal = $(
        '<div id="csmf-preview-modal" class="csmf-modal">' +
          '<div class="csmf-modal-content">' +
          '<span class="csmf-modal-close">&times;</span>' +
          '<div class="csmf-modal-body"></div>' +
          "</div>" +
          "</div>"
      );

      $("body").append($modal);

      // Close modal when clicking on the close button
      $(".csmf-modal-close").on("click", function () {
        $("#csmf-preview-modal").hide();
      });

      // Close modal when clicking outside of it
      $(window).on("click", function (e) {
        if ($(e.target).is("#csmf-preview-modal")) {
          $("#csmf-preview-modal").hide();
        }
      });
    }

    // Add preview button to each template card
    $(".csmf-template-card").each(function () {
      var $card = $(this);
      var $preview = $card.find(".csmf-template-preview");

      // Add preview button if it doesn't exist
      if ($preview.find(".csmf-preview-button").length === 0) {
        var $previewBtn = $(
          '<button class="csmf-preview-button" title="Preview Template">' +
            '<span class="dashicons dashicons-visibility"></span>' +
            "</button>"
        );

        $preview.append($previewBtn);

        // Show preview when clicking on the button
        $previewBtn.on("click", function (e) {
          e.preventDefault();
          e.stopPropagation();

          var templateId = $card.find(".csmf-import-btn").data("id");
          var template = templates[templateId];

          if (!template) return;

          var $modalBody = $(".csmf-modal-body");
          $modalBody.empty();

          // Add template details to modal
          var $content = $(
            '<div class="csmf-preview-content">' +
              "<h2>" +
              template.title +
              "</h2>" +
              '<div class="csmf-preview-image">' +
              '<img src="' +
              (template.thumbnail || "") +
              '" alt="' +
              template.title +
              '">' +
              "</div>" +
              '<div class="csmf-preview-details"></div>' +
              '<div class="csmf-preview-actions">' +
              '<button class="button csmf-preview-import" data-type="page" data-id="' +
              templateId +
              '">Import as Page</button>' +
              '<button class="button button-primary csmf-preview-import" data-type="template" data-id="' +
              templateId +
              '">Import as Template</button>' +
              "</div>" +
              "</div>"
          );

          // Add details
          var $details = $content.find(".csmf-preview-details");

          if (template.category) {
            $details.append("<p><strong>Category:</strong> " + template.category + "</p>");
          }

          if (template.description) {
            $details.append("<p><strong>Description:</strong> " + template.description + "</p>");
          }

          $modalBody.append($content);

          // Show modal
          $("#csmf-preview-modal").show();

          // Handle import button click
          $(".csmf-preview-import").on("click", function () {
            var importType = $(this).data("type");
            var templateId = $(this).data("id");
            var $originalBtn = $card.find('.csmf-import-btn[data-type="' + importType + '"]');

            // Hide modal
            $("#csmf-preview-modal").hide();

            // Trigger import
            if ($originalBtn.length) {
              $originalBtn.trigger("click");
            }
          });
        });
      }
    });
  }

  /**
   * Add template info tooltip
   */
  function addTemplateTooltips() {
    $(document).on("mouseenter", ".csmf-template-card", function () {
      var $card = $(this);
      if (!$card.data("tooltip-added")) {
        $card.data("tooltip-added", true);
        $card.append('<div class="csmf-template-tooltip">Click Import button to use this template</div>');

        setTimeout(function () {
          $card.find(".csmf-template-tooltip").fadeOut(function () {
            $(this).remove();
            $card.data("tooltip-added", false);
          });
        }, 3000);
      }
    });
  }

  /**
   * Handle keyboard shortcuts
   */
  function setupKeyboardShortcuts() {
    $(document).on("keydown", function (e) {
      // Ctrl+Shift+I focuses search input
      if (e.ctrlKey && e.shiftKey && e.key === "I") {
        e.preventDefault();
        $(".csmf-search-input").focus();
      }

      // ESC key closes modals
      if (e.key === "Escape") {
        $("#csmf-preview-modal").hide();
      }
    });
  }

  /**
   * Add integration with Elementor template library
   */
  function addTemplateLibraryIntegration() {
    console.log("Template library integration starting");

    // If elementor is not initialized yet, wait for it
    if (!elementor.templates) {
      console.log("Elementor templates not ready, waiting...");
      setTimeout(addTemplateLibraryIntegration, 1000);
      return;
    }

    console.log("Hooking into Elementor events");

    // Hook into template library open event
    elementor.channels.editor.on("library:open", function () {
      console.log("Template library opened");
      setTimeout(function () {
        console.log("Checking for local templates tab");
        if ($("#elementor-template-library-tabs-tab-local").length) {
          $("#elementor-template-library-tabs-tab-local").on("click", function () {
            console.log("Local templates tab clicked");
            setTimeout(checkAndAddConvertButton, 500);
          });

          // Also check if it's already the active tab
          if ($("#elementor-template-library-tabs-tab-local.elementor-active").length) {
            console.log("Local templates tab is active");
            setTimeout(checkAndAddConvertButton, 500);
          }
        }
      }, 500);
    });
  }

  /**
   * Check and add convert button if needed
   */
  function checkAndAddConvertButton() {
    console.log("Checking for template list");

    // If the template list is not loaded yet, wait
    if (!$(".elementor-template-library-template-local").length) {
      console.log("Template list not loaded yet, retrying...");
      setTimeout(checkAndAddConvertButton, 500);
      return;
    }

    console.log("Template list found, setting up click handlers");

    // Set up click handlers for template items
    $(".elementor-template-library-template-local")
      .off("click.csmfTemplateConvert")
      .on("click.csmfTemplateConvert", function () {
        var $template = $(this);
        var templateId = $template.data("template-id");
        console.log("Template clicked:", templateId);

        setTimeout(function () {
          addConvertButtonToActiveTemplate(templateId);
        }, 300);
      });
  }

  /**
   * Add convert button to active template
   */
  function addConvertButtonToActiveTemplate(templateId) {
    console.log("Adding convert button for template:", templateId);

    // Check if header actions area exists
    var $actions = $("#elementor-template-library-header-actions");
    if (!$actions.length) {
      console.log("Header actions not found");
      return;
    }

    // Check if button already exists
    if ($("#csmf-convert-to-page").length) {
      console.log("Convert button already exists");
      return;
    }

    console.log("Adding convert button to header actions");

    // Add the button
    $actions.append(
      '<div id="csmf-convert-to-page" class="elementor-template-library-template-action elementor-button elementor-button-success">' +
        '<i class="eicon-file-download"></i>' +
        '<span class="elementor-button-title">' +
        (i18n.convertToPage || "Convert to Page") +
        "</span>" +
        "</div>"
    );

    // Add click handler
    $("#csmf-convert-to-page").on("click", function () {
      console.log("Convert button clicked for template:", templateId);

      // Show loading
      var loadingOverlay = createLoadingOverlay();
      $("body").append(loadingOverlay);

      $.ajax({
        url: ajaxUrl,
        type: "POST",
        data: {
          action: "csmf_convert_template_to_page",
          template_id: templateId,
          nonce: nonce,
        },
        success: function (response) {
          loadingOverlay.remove();
          if (response.success) {
            console.log("Template conversion successful");
            showConversionSuccessPopup(response.data);
          } else {
            console.log("Template conversion failed:", response.data);
            alert(response.data.message || "Error converting template to page");
          }
        },
        error: function (xhr, status, error) {
          console.log("AJAX error:", error);
          loadingOverlay.remove();
          alert(i18n.errorServer || "Error connecting to the server");
        },
      });
    });
  }

  /**
   * Add convert button to template library
   */
  function addConvertButtonToTemplateLibrary() {
    // Check if button already exists
    if ($("#csmf-convert-to-page").length) {
      return;
    }

    // Add button after existing buttons
    $(document).on("click", ".elementor-template-library-template-local", function () {
      setTimeout(function () {
        if ($("#elementor-template-library-header-actions").length && !$("#csmf-convert-to-page").length) {
          $("#elementor-template-library-header-actions").append(
            '<div id="csmf-convert-to-page" class="elementor-template-library-template-action elementor-button elementor-button-success">' +
              '<i class="eicon-file-download"></i>' +
              '<span class="elementor-button-title">' +
              (i18n.convertToPage || "Convert to Page") +
              "</span>" +
              "</div>"
          );

          // Add click handler
          $("#csmf-convert-to-page").on("click", function () {
            var templateId = $(".elementor-template-library-template-local.elementor-active").data("template-id");
            if (!templateId) {
              alert(i18n.selectTemplate || "Please select a template first");
              return;
            }

            // Show loading
            var loadingOverlay = createLoadingOverlay();
            $("body").append(loadingOverlay);

            $.ajax({
              url: ajaxUrl,
              type: "POST",
              data: {
                action: "csmf_convert_template_to_page",
                template_id: templateId,
                nonce: nonce,
              },
              success: function (response) {
                loadingOverlay.remove();
                if (response.success) {
                  // Show success popup
                  showConversionSuccessPopup(response.data);
                } else {
                  alert(response.data.message || "Error converting template to page");
                }
              },
              error: function () {
                loadingOverlay.remove();
                alert(i18n.errorServer || "Error connecting to the server");
              },
            });
          });
        }
      }, 300);
    });
  }

  /**
   * Show conversion success popup
   */
  function showConversionSuccessPopup(data) {
    // Create popup HTML
    var popup = $(
      '<div class="csmf-popup-overlay">' +
        '<div class="csmf-popup-container">' +
        '<div class="csmf-popup-header">' +
        "<h3>" +
        (data.title || i18n.conversionSuccess || "Success!") +
        "</h3>" +
        '<button class="csmf-popup-close">&times;</button>' +
        "</div>" +
        '<div class="csmf-popup-body">' +
        "<p>" +
        data.message +
        "</p>" +
        '<div class="csmf-popup-buttons">' +
        '<a href="' +
        data.preview_url +
        '" target="_blank" class="button">' +
        '<span class="dashicons dashicons-visibility"></span> ' +
        (i18n.preview || "Preview") +
        "</a>" +
        '<a href="' +
        data.edit_url +
        '" class="button button-primary">' +
        '<span class="dashicons dashicons-edit"></span> ' +
        (i18n.edit || "Edit") +
        "</a>" +
        "</div>" +
        "</div>" +
        "</div>" +
        "</div>"
    );

    // Add popup to body
    $("body").append(popup);

    // Add close handlers
    setupPopupCloseHandlers(popup);
  }

  /**
   * Handle convert button click
   * @param {Event} e - The click event
   */
  function handleConvertButtonClick(e) {
    e.preventDefault();

    var $button = $(this);
    var templateId = $button.data("template-id");

    if (!templateId) {
      alert(csmfTemplateConverter.i18n.noTemplateSelected || "No template selected");
      return;
    }

    // Save the original text
    var originalText = $button.text();

    // Show loading text
    $button.text(csmfTemplateConverter.i18n.converting || "Converting...");

    // Create and show loading overlay
    showLoadingOverlay();

    // Send AJAX request
    $.ajax({
      url: csmfTemplateConverter.ajaxUrl,
      type: "POST",
      data: {
        action: "csmf_convert_template_to_page",
        template_id: templateId,
        nonce: csmfTemplateConverter.nonce,
      },
      success: function (response) {
        // Reset button text
        $button.text(originalText);

        // Remove loading overlay
        removeLoadingOverlay();

        if (response.success) {
          // Show success message with popup
          showSuccessPopup(response.data);
        } else {
          // Show error message
          var errorMsg = "Error converting template to page";
          if (response.data && response.data.message) {
            errorMsg = response.data.message;
          }
          alert(errorMsg);
        }
      },
      error: function () {
        // Reset button text
        $button.text(originalText);

        // Remove loading overlay
        removeLoadingOverlay();

        // Show error message
        alert(csmfTemplateConverter.i18n.errorServer || "Error connecting to the server");
      },
    });
  }

  /**
   * Show loading overlay
   */
  function showLoadingOverlay() {
    // Remove any existing overlays
    removeLoadingOverlay();

    // Create loading overlay HTML
    var loadingHTML =
      '<div class="csmf-loading-overlay">' +
      '<div class="csmf-loading-spinner">' +
      '<div class="csmf-loading-bounce1"></div>' +
      '<div class="csmf-loading-bounce2"></div>' +
      '<div class="csmf-loading-bounce3"></div>' +
      "</div>" +
      '<div class="csmf-loading-text">' +
      (csmfTemplateConverter.i18n.converting || "Converting...") +
      "</div>" +
      "</div>";

    // Add loading overlay to body
    $("body").append(loadingHTML);
  }

  /**
   * Remove loading overlay
   */
  function removeLoadingOverlay() {
    $(".csmf-loading-overlay").remove();
  }

  /**
   * Show success popup
   * @param {Object} data - Response data from server
   */
  function showSuccessPopup(data) {
    // Remove any existing popups
    $(".csmf-popup-overlay").remove();

    // Ensure we have all required data
    var message = data.message || "Template successfully converted to page!";
    var previewUrl = data.preview_url || "#";
    var editUrl = data.edit_url || "#";

    // Create popup HTML
    var popupHTML =
      '<div class="csmf-popup-overlay">' +
      '<div class="csmf-popup">' +
      '<div class="csmf-popup-header">' +
      "<h3>" +
      (csmfTemplateConverter.i18n.successTitle || "Success!") +
      "</h3>" +
      '<button class="csmf-popup-close">&times;</button>' +
      "</div>" +
      '<div class="csmf-popup-body">' +
      "<p>" +
      message +
      "</p>" +
      "</div>" +
      '<div class="csmf-popup-footer">' +
      '<a href="' +
      previewUrl +
      '" target="_blank" class="button">' +
      '<span class="dashicons dashicons-visibility"></span> ' +
      (csmfTemplateConverter.i18n.preview || "Preview") +
      "</a>" +
      '<a href="' +
      editUrl +
      '" class="button button-primary">' +
      '<span class="dashicons dashicons-edit"></span> ' +
      (csmfTemplateConverter.i18n.edit || "Edit with Elementor") +
      "</a>" +
      "</div>" +
      "</div>" +
      "</div>";

    // Add popup to body
    $("body").append(popupHTML);
  }

  /**
   * Show success notice using WordPress notifications
   */
  function showSuccessNotice(data) {
    // Create success message
    var $notice = $('<div id="csmf-success-message" class="notice notice-success is-dismissible"><p></p></div>');
    var $content = $notice.find("p");

    // Add message
    $content.text(data.message);

    // Add action buttons
    var $actions = $('<div class="csmf-notice-actions" style="margin-top: 10px;"></div>');

    // Preview button
    var $previewBtn = $(
      '<a href="' +
        data.preview_url +
        '" target="_blank" class="button">' +
        '<span class="dashicons dashicons-visibility" style="margin-top: 3px;"></span> ' +
        (csmfTemplateConverter.i18n.preview || "Preview") +
        "</a>"
    );
    $actions.append($previewBtn);

    // Edit button
    var $editBtn = $(
      '<a href="' +
        data.edit_url +
        '" class="button button-primary" style="margin-left: 5px;">' +
        '<span class="dashicons dashicons-edit" style="margin-top: 3px;"></span> ' +
        (csmfTemplateConverter.i18n.edit || "Edit with Elementor") +
        "</a>"
    );
    $actions.append($editBtn);

    // Add actions to notice
    $content.append($actions);

    // Add notice to page
    $("#wpbody-content").prepend($notice);

    // Scroll to top to make sure notice is visible
    $("html, body").animate({ scrollTop: 0 }, "fast");

    // Remove notice after 10 seconds if not dismissed
    setTimeout(function () {
      $notice.fadeOut(500, function () {
        $(this).remove();
      });
    }, 10000);
  }

  /**
   * Show conversion success message
   * @param {Object} data - Response data from server
   */
  function showConversionSuccessMessage(data) {
    // Log data for debugging
    console.log("Creating popup with data:", data);

    // Ensure we have all required data or provide defaults
    var message = data.message || "Template successfully converted to page!";
    var previewUrl = data.preview_url || "#";
    var editUrl = data.edit_url || "#";

    // Remove any existing popups first
    $(".csmf-modal-overlay").remove();

    // Create modal HTML
    var modalHTML =
      '<div class="csmf-modal-overlay" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 99999; display: flex; justify-content: center; align-items: center;">' +
      '<div class="csmf-modal" style="background: white; border-radius: 3px; box-shadow: 0 2px 30px rgba(0,0,0,0.3); width: 500px; max-width: 90%; padding: 20px; position: relative; z-index: 100000;">' +
      '<h2 style="margin-top: 0; color: #23282d;">' +
      (csmfTemplateConverter.i18n.successTitle || "Template Converted Successfully") +
      "</h2>" +
      '<p style="margin-bottom: 1.5em;">' +
      message +
      "</p>" +
      '<div class="csmf-modal-actions" style="margin-top: 20px; text-align: right;">' +
      '<a href="' +
      previewUrl +
      '" target="_blank" class="button" style="margin-left: 5px;">' +
      '<span class="dashicons dashicons-visibility" style="vertical-align: middle; margin-right: 3px;"></span> ' +
      (csmfTemplateConverter.i18n.preview || "Preview") +
      "</a> " +
      '<a href="' +
      editUrl +
      '" class="button button-primary" style="margin-left: 5px;">' +
      '<span class="dashicons dashicons-edit" style="vertical-align: middle; margin-right: 3px;"></span> ' +
      (csmfTemplateConverter.i18n.edit || "Edit with Elementor") +
      "</a> " +
      '<button class="button csmf-modal-close" style="margin-left: 5px;">' +
      (csmfTemplateConverter.i18n.close || "Close") +
      "</button>" +
      "</div>" +
      "</div>" +
      "</div>";

    // Add modal to body with inline styles
    var $modal = $(modalHTML);
    $("body").append($modal);

    // Ensure the modal is visible by manually setting display style
    $modal.css("display", "flex");

    // Log to confirm the modal was added
    console.log("Modal added to DOM:", $modal.length > 0);

    // Close modal on button click
    $modal.find(".csmf-modal-close").on("click", function () {
      console.log("Close button clicked");
      $modal.remove();
    });

    // Close modal when clicking outside
    $modal.on("click", function (e) {
      if ($(e.target).hasClass("csmf-modal-overlay")) {
        console.log("Clicked outside modal");
        $modal.remove();
      }
    });

    // Add a console log to check if the popup is created but hidden by some CSS
    setTimeout(function () {
      console.log("Modal still in DOM:", $(".csmf-modal-overlay").length > 0);
      console.log("Modal visibility:", $(".csmf-modal-overlay").css("display"));
    }, 500);
  }

  // Initialize everything when document is ready
  $(document).ready(function () {
    init();
    initTooltips();
    addTemplateTooltips();
    setupKeyboardShortcuts();
  });
})(jQuery);
