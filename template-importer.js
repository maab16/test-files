/**
 * Template Importer JavaScript
 *
 * Handles the UI interactions and AJAX calls for template importing and conversion
 *
 * @package CodexShaper_Framework
 * @since 1.0.0
 */

(function ($) {
  "use strict";

  // Main App object
  const TemplateImporter = {
    // Configuration
    config: {
      ajaxUrl: csmfTemplateImporter.ajaxUrl || "",
      nonce: csmfTemplateImporter.nonce || "",
      i18n: csmfTemplateImporter.i18n || {},
      selectors: {
        tabs: ".nav-tab",
        remoteTemplatesTab: "#remote-templates-tab",
        templatesLoader: ".csmf-templates-loader",
        templatesGrid: ".csmf-templates-grid",
        filterContainer: ".csmf-filter-container",
        searchInput: ".csmf-search-input",
        refreshButton: ".csmf-refresh-button",
        popupOverlay: ".csmf-popup-overlay",
        popupClose: ".csmf-popup-close",
        convertButton: ".csmf-convert-template",
        importButtons: ".csmf-import-btn",
        fileInput: "#csmf-template-file",
        fileName: ".csmf-file-name",
        uploadForm: "#csmf-template-upload-form",
      },
    },

    // State
    state: {
      templatesLoaded: false,
      templates: {},
    },

    /**
     * Initialize the template importer
     */
    init: function () {
      this.registerEventHandlers();
      this.checkActiveTab();
      this.addBackToTopButton();
      this.checkElementorAvailability();
    },

    /**
     * Register all event handlers
     */
    registerEventHandlers: function () {
      const self = this;
      const s = this.config.selectors;

      // Tab Navigation
      $(s.tabs).on("click", this.handleTabClick.bind(this));

      // Refresh templates
      $(s.refreshButton).on("click", this.handleRefreshTemplates.bind(this));

      // Search and filter
      this.setupSearch();

      // Form handling
      this.setupFormHandlers();

      // Popup and template interactions
      $(document).on("click", s.popupClose, this.closePopup);
      $(document).on("click", s.popupOverlay, this.handleOverlayClick);
      $(document).on("click", s.convertButton, this.handleConvertButtonClick.bind(this));

      // Keyboard events
      $(document).on("keydown", function (e) {
        if (e.key === "Escape" && $(s.popupOverlay).length > 0) {
          self.closePopup();
        }
      });

      // Initialize tooltips
      this.initTooltips();
      this.addTemplateTooltips();
      this.setupKeyboardShortcuts();
    },

    /**
     * Check active tab and load content if needed
     */
    checkActiveTab: function () {
      if ($(this.config.selectors.remoteTemplatesTab).is(":visible") && !this.state.templatesLoaded) {
        this.loadTemplates();
      }
    },

    /**
     * Handle tab click
     *
     * @param {Event} e Click event
     */
    handleTabClick: function (e) {
      e.preventDefault();

      // Update active tab
      $(this.config.selectors.tabs).removeClass("nav-tab-active");
      $(e.currentTarget).addClass("nav-tab-active");

      // Hide all tab contents
      $(".csmf-tab-content").hide();

      // Show selected tab content
      const targetId = $(e.currentTarget).attr("href") + "-tab";
      $(targetId).show();

      // Load templates if needed
      if (targetId === "#remote-templates-tab" && !this.state.templatesLoaded) {
        this.loadTemplates();
      }
    },

    /**
     * Handle refresh templates button click
     */
    handleRefreshTemplates: function () {
      this.state.templatesLoaded = false;
      this.loadTemplates();
    },

    /**
     * Load templates via AJAX
     */
    loadTemplates: function () {
      const self = this;
      const s = this.config.selectors;

      $(s.templatesLoader).show();
      $(s.templatesGrid).hide();
      $(s.filterContainer).empty();

      $.ajax({
        url: this.config.ajaxUrl,
        type: "POST",
        data: {
          action: "csmf_fetch_templates",
          nonce: this.config.nonce,
        },
        success: function (response) {
          $(s.templatesLoader).hide();

          if (response.success && response.data && response.data.templates) {
            self.state.templates = response.data.templates;
            self.state.templatesLoaded = true;
            self.renderTemplates(response.data.templates);
            self.addCategoryFilter(response.data.templates);
          } else {
            self.showError(
              response.data ? response.data.message : self.config.i18n.noTemplatesFound || "Failed to load templates"
            );
          }
        },
        error: function (xhr, status, error) {
          $(s.templatesLoader).hide();
          self.showError("Error loading templates: " + error);
        },
      });
    },

    /**
     * Render templates in the grid
     *
     * @param {Object} templates Templates object
     */
    renderTemplates: function (templates) {
      const self = this;
      const i18n = this.config.i18n;
      const $grid = $(this.config.selectors.templatesGrid);

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

        const templateId = template.id || id;
        const title = template.title || "Unknown Template";
        const thumbnail = template.thumbnail || "";
        const category = template.category || "";
        const pageImportUrl = template.page_import_url || "";
        const templateImportUrl = template.template_import_url || "";
        const previewUrl = template.preview_url || thumbnail;

        const $card = $('<div class="csmf-template-card"></div>');

        // Add category as data attribute
        if (category) {
          $card.attr("data-category", category);
        }

        // Template preview
        const $preview = $('<div class="csmf-template-preview"></div>');
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
          const $previewBtn = $(
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
        const $info = $('<div class="csmf-template-info"></div>');
        $info.append("<h3>" + title + "</h3>");

        // Template actions
        const $actions = $('<div class="csmf-template-actions"></div>');

        // Import as Page button
        const $pageBtn = $(
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
        const $templateBtn = $(
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
      $(this.config.selectors.importButtons).on("click", this.handleTemplateImport.bind(this));
    },

    /**
     * Handle template import button click
     *
     * @param {Event} e Click event
     */
    handleTemplateImport: function (e) {
      const self = this;
      const $button = $(e.currentTarget);
      const originalText = $button.text();
      const templateId = $button.data("id");
      const templateUrl = $button.data("url");
      const templateTitle = $button.data("title");
      const importType = $button.data("type");

      // Show loading overlay
      const $loadingOverlay = this.createLoadingOverlay();
      $("body").append($loadingOverlay);

      $.ajax({
        url: this.config.ajaxUrl,
        type: "POST",
        data: {
          action: "csmf_import_template",
          template_id: templateId,
          import_type: importType,
          template_title: templateTitle,
          nonce: this.config.nonce,
        },
        success: function (response) {
          // Remove loading overlay
          $loadingOverlay.remove();

          if (response.success) {
            // Show success popup
            self.showImportSuccessPopup(response.data, importType);
          } else {
            // Show error and reset button
            $button.prop("disabled", false).text(originalText);
            const errorMsg = response.data ? response.data.message : "Import failed";
            self.showError(errorMsg, $button.closest(".csmf-template-card"));
          }
        },
        error: function (xhr, status, error) {
          // Remove loading overlay
          $loadingOverlay.remove();

          // Show error and reset button
          $button.prop("disabled", false).text(originalText);
          self.showError("Error importing template: " + error, $button.closest(".csmf-template-card"));
        },
      });
    },

    /**
     * Create loading overlay
     *
     * @returns {jQuery} Loading overlay element
     */
    createLoadingOverlay: function () {
      return $(
        '<div class="csmf-loading-overlay">' +
          '<div class="csmf-loading-spinner">' +
          '<div class="csmf-loading-bounce1"></div>' +
          '<div class="csmf-loading-bounce2"></div>' +
          '<div class="csmf-loading-bounce3"></div>' +
          "</div>" +
          '<div class="csmf-loading-text">' +
          (this.config.i18n.importing || "Importing...") +
          "</div>" +
          "</div>"
      );
    },

    /**
     * Show import success popup
     *
     * @param {Object} data Response data
     * @param {string} importType Import type (page|template)
     */
    showImportSuccessPopup: function (data, importType) {
      const self = this;
      const i18n = this.config.i18n;

      // Format post type for display
      const postTypeText = importType === "page" ? i18n.pageText || "Page" : i18n.templateText || "Template";

      // Create popup HTML
      const popup = $(
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
        const templateId = $(this).data("template-id");
        self.convertTemplateToPage(popup, templateId);
      });

      // Add close handlers
      this.setupPopupCloseHandlers(popup);
    },

    /**
     * Convert template to page
     *
     * @param {jQuery} popup The popup element
     * @param {number} templateId Template ID
     */
    convertTemplateToPage: function (popup, templateId) {
      const self = this;
      const i18n = this.config.i18n;

      $.ajax({
        url: this.config.ajaxUrl,
        type: "POST",
        data: {
          action: "csmf_convert_template_to_page",
          template_id: templateId,
          nonce: this.config.nonce,
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
    },

    /**
     * Setup popup close handlers
     *
     * @param {jQuery} popup The popup element
     */
    setupPopupCloseHandlers: function (popup) {
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
    },

    /**
     * Close all popups
     */
    closePopup: function () {
      $(".csmf-popup-overlay").remove();
    },

    /**
     * Handle clicks on popup overlay
     *
     * @param {Event} e Click event
     */
    handleOverlayClick: function (e) {
      if ($(e.target).hasClass("csmf-popup-overlay")) {
        TemplateImporter.closePopup();
      }
    },

    /**
     * Setup form handlers
     */
    setupFormHandlers: function () {
      const self = this;
      const s = this.config.selectors;
      const i18n = this.config.i18n;

      // File input name display
      $(s.fileInput).on("change", function () {
        const fileName = $(this).val().split("\\").pop();
        if (fileName) {
          $(s.fileName).text(fileName);
        } else {
          $(s.fileName).text(i18n.noFileSelected || "No file selected");
        }
      });

      // Handle file upload form submission
      $(s.uploadForm).on("submit", function (e) {
        e.preventDefault();

        const $form = $(this);
        const $submitBtn = $form.find(".csmf-upload-button");
        const $progressBar = $form.find(".csmf-upload-progress");
        const $progressInner = $progressBar.find(".csmf-progress-bar-inner");
        const $progressStatus = $progressBar.find(".csmf-progress-status");
        const $response = $form.find(".csmf-upload-response");

        // Check if file is selected
        const fileInput = $form.find('input[type="file"]')[0];
        if (!fileInput.files.length) {
          self.showError("Please select a file to upload", $response);
          return;
        }

        // Prepare form data
        const formData = new FormData($form[0]);
        formData.append("action", "csmf_upload_template");
        formData.append("nonce", self.config.nonce);

        // Show progress bar and disable submit button
        $submitBtn.prop("disabled", true);
        $progressBar.show();
        $response.empty();

        $.ajax({
          url: self.config.ajaxUrl,
          type: "POST",
          data: formData,
          contentType: false,
          processData: false,
          xhr: function () {
            const xhr = new window.XMLHttpRequest();

            // Upload progress
            xhr.upload.addEventListener(
              "progress",
              function (e) {
                if (e.lengthComputable) {
                  const percent = Math.round((e.loaded / e.total) * 100);
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
                $(s.fileName).text(i18n.noFileSelected || "No file selected");

                // Redirect to the imported template
                setTimeout(function () {
                  if (response.data && response.data.edit_url) {
                    window.location.href = response.data.edit_url;
                  }
                }, 1500);
              } else {
                // Show error
                self.showError(response.data ? response.data.message : "Upload failed", $response);
              }
            }, 500);
          },
          error: function (xhr, status, error) {
            // Hide progress and enable button
            $progressBar.hide();
            $submitBtn.prop("disabled", false);

            // Show error
            self.showError("Error uploading template: " + error, $response);
          },
        });
      });
    },

    /**
     * Setup search functionality
     */
    setupSearch: function () {
      const self = this;
      const s = this.config.selectors;

      $(s.searchInput).on("keyup", function () {
        const searchTerm = $(this).val().toLowerCase();

        $(".csmf-template-card").each(function () {
          const $card = $(this);
          const title = $card.find("h3").text().toLowerCase();
          const category = $card.attr("data-category") ? $card.attr("data-category").toLowerCase() : "";

          if (title.indexOf(searchTerm) > -1 || category.indexOf(searchTerm) > -1) {
            $card.show();
          } else {
            $card.hide();
          }
        });

        // Show no results message
        if ($(".csmf-template-card:visible").length === 0 && searchTerm !== "") {
          if ($(".csmf-no-results").length === 0) {
            $(s.templatesGrid).append(
              '<div class="csmf-no-results">No templates found matching "' + searchTerm + '"</div>'
            );
          }
        } else {
          $(".csmf-no-results").remove();
        }
      });
    },

    /**
     * Add category filter
     *
     * @param {Object} templates Templates object
     */
    addCategoryFilter: function (templates) {
      const self = this;
      const s = this.config.selectors;
      const i18n = this.config.i18n;

      // Check if templates have categories
      let hasCategories = false;
      const categories = {};

      $.each(templates, function (id, template) {
        if (template.category && template.category.trim() !== "") {
          hasCategories = true;
          categories[template.category] = true;
        }
      });

      if (hasCategories) {
        const $filterContainer = $(s.filterContainer);
        const $filterLabel = $('<span class="csmf-filter-label">' + (i18n.filterBy || "Filter by:") + " </span>");
        const $filterSelect = $('<select class="csmf-category-filter"></select>');

        // Add "All" option
        $filterSelect.append('<option value="all">' + (i18n.allCategories || "All Categories") + "</option>");

        // Add category options
        $.each(Object.keys(categories).sort(), function (i, category) {
          $filterSelect.append('<option value="' + category + '">' + category + "</option>");
        });

        $filterContainer.append($filterLabel).append($filterSelect);

        // Handle category filtering
        $filterSelect.on("change", function () {
          const selectedCategory = $(this).val();

          $(".csmf-template-card").each(function () {
            const $card = $(this);
            const category = $card.attr("data-category") || "";

            if (selectedCategory === "all" || category === selectedCategory) {
              $card.show();
            } else {
              $card.hide();
            }
          });

          // Show no results message
          if ($(".csmf-template-card:visible").length === 0) {
            if ($(".csmf-no-results").length === 0) {
              $(s.templatesGrid).append(
                '<div class="csmf-no-results">No templates found in category "' + selectedCategory + '"</div>'
              );
            }
          } else {
            $(".csmf-no-results").remove();
          }
        });
      }
    },

    /**
     * Show error message
     *
     * @param {string} message Error message
     * @param {jQuery} $container Container to append the error to (optional)
     */
    showError: function (message, $container) {
      const $error = $('<div class="csmf-error"></div>').text(message);

      if (!$container) {
        $container = $(this.config.selectors.templatesGrid);
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
    },

    /**
     * Add back to top button
     */
    addBackToTopButton: function () {
      const $backToTop = $(
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
    },

    /**
     * Initialize tooltips
     */
    initTooltips: function () {
      $(document).on("mouseenter", "[data-tooltip]", function () {
        const $el = $(this);
        const tooltipText = $el.attr("data-tooltip");

        const $tooltip = $('<div class="csmf-tooltip"></div>').text(tooltipText);
        $("body").append($tooltip);

        const elOffset = $el.offset();
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
    },

    /**
     * Add template info tooltip
     */
    addTemplateTooltips: function () {
      $(document).on("mouseenter", ".csmf-template-card", function () {
        const $card = $(this);
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
    },

    /**
     * Handle keyboard shortcuts
     */
    setupKeyboardShortcuts: function () {
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
    },

    /**
     * Check if Elementor is available and setup integration
     */
    checkElementorAvailability: function () {
      const self = this;

      setTimeout(function () {
        if (typeof elementor !== "undefined") {
          console.log("Elementor found, adding integration");
          self.addTemplateLibraryIntegration();
        } else {
          console.log("Elementor not available, setting up MutationObserver");
          self.setupElementorWatcher();
        }
      }, 1000);
    },

    /**
     * Set up a watcher to detect when Elementor becomes available
     */
    setupElementorWatcher: function () {
      // Check if we're on an admin page that might load Elementor
      if (
        window.location.href.indexOf("post.php") > -1 ||
        window.location.href.indexOf("post-new.php") > -1 ||
        window.location.href.indexOf("edit.php") > -1
      ) {
        console.log("Setting up Elementor watcher");

        const self = this;
        // Set an interval to check for Elementor
        const checkElementor = setInterval(function () {
          if (typeof elementor !== "undefined") {
            console.log("Elementor detected by watcher");
            clearInterval(checkElementor);
            self.addTemplateLibraryIntegration();
          }
        }, 1000);

        // Clear after 60 seconds to avoid endless checking
        setTimeout(function () {
          clearInterval(checkElementor);
        }, 60000);
      }
    },

    /**
     * Add integration with Elementor template library
     */
    addTemplateLibraryIntegration: function () {
      const self = this;
      console.log("Template library integration starting");

      // If elementor is not initialized yet, wait for it
      if (!elementor.templates) {
        console.log("Elementor templates not ready, waiting...");
        setTimeout(function () {
          self.addTemplateLibraryIntegration();
        }, 1000);
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
              setTimeout(function () {
                self.checkAndAddConvertButton();
              }, 500);
            });

            // Also check if it's already the active tab
            if ($("#elementor-template-library-tabs-tab-local.elementor-active").length) {
              console.log("Local templates tab is active");
              setTimeout(function () {
                self.checkAndAddConvertButton();
              }, 500);
            }
          }
        }, 500);
      });
    },

    /**
     * Check and add convert button if needed
     */
    checkAndAddConvertButton: function () {
      const self = this;
      console.log("Checking for template list");

      // If the template list is not loaded yet, wait
      if (!$(".elementor-template-library-template-local").length) {
        console.log("Template list not loaded yet, retrying...");
        setTimeout(function () {
          self.checkAndAddConvertButton();
        }, 500);
        return;
      }

      console.log("Template list found, setting up click handlers");

      // Set up click handlers for template items
      $(".elementor-template-library-template-local")
        .off("click.csmfTemplateConvert")
        .on("click.csmfTemplateConvert", function () {
          const $template = $(this);
          const templateId = $template.data("template-id");
          console.log("Template clicked:", templateId);

          setTimeout(function () {
            self.addConvertButtonToActiveTemplate(templateId);
          }, 300);
        });
    },

    /**
     * Add convert button to active template
     *
     * @param {number} templateId Template ID
     */
    addConvertButtonToActiveTemplate: function (templateId) {
      const self = this;
      const i18n = this.config.i18n;

      console.log("Adding convert button for template:", templateId);

      // Check if header actions area exists
      const $actions = $("#elementor-template-library-header-actions");
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
        const loadingOverlay = self.createLoadingOverlay();
        $("body").append(loadingOverlay);

        $.ajax({
          url: self.config.ajaxUrl,
          type: "POST",
          data: {
            action: "csmf_convert_template_to_page",
            template_id: templateId,
            nonce: self.config.nonce,
          },
          success: function (response) {
            loadingOverlay.remove();
            if (response.success) {
              console.log("Template conversion successful");
              self.showConversionSuccessPopup(response.data);
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
    },

    /**
     * Add convert button to template library
     */
    addConvertButtonToTemplateLibrary: function () {
      const self = this;
      const i18n = this.config.i18n;

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
              const templateId = $(".elementor-template-library-template-local.elementor-active").data("template-id");
              if (!templateId) {
                alert(i18n.selectTemplate || "Please select a template first");
                return;
              }

              // Show loading
              const loadingOverlay = self.createLoadingOverlay();
              $("body").append(loadingOverlay);

              $.ajax({
                url: self.config.ajaxUrl,
                type: "POST",
                data: {
                  action: "csmf_convert_template_to_page",
                  template_id: templateId,
                  nonce: self.config.nonce,
                },
                success: function (response) {
                  loadingOverlay.remove();
                  if (response.success) {
                    // Show success popup
                    self.showConversionSuccessPopup(response.data);
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
    },

    /**
     * Show conversion success popup
     *
     * @param {Object} data Response data
     */
    showConversionSuccessPopup: function (data) {
      const i18n = this.config.i18n;

      // Create popup HTML
      const popup = $(
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
      this.setupPopupCloseHandlers(popup);
    },

    /**
     * Handle convert button click
     *
     * @param {Event} e Click event
     */
    handleConvertButtonClick: function (e) {
      e.preventDefault();

      const $button = $(e.currentTarget);
      const templateId = $button.data("template-id");
      const i18n = csmfTemplateConverter.i18n || {};

      if (!templateId) {
        alert(i18n.noTemplateSelected || "No template selected");
        return;
      }

      // Save the original text
      const originalText = $button.text();

      // Show loading text
      $button.text(i18n.converting || "Converting...");

      // Create and show loading overlay
      this.showLoadingOverlay();

      // Send AJAX request
      const self = this;
      $.ajax({
        url: this.config.ajaxUrl,
        type: "POST",
        data: {
          action: "csmf_convert_template_to_page",
          template_id: templateId,
          nonce: this.config.nonce,
        },
        success: function (response) {
          // Reset button text
          $button.text(originalText);

          // Remove loading overlay
          self.removeLoadingOverlay();

          if (response.success) {
            // Show success message with popup
            self.showSuccessPopup(response.data);
          } else {
            // Show error message
            let errorMsg = "Error converting template to page";
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
          self.removeLoadingOverlay();

          // Show error message
          alert(i18n.errorServer || "Error connecting to the server");
        },
      });
    },

    /**
     * Show loading overlay
     */
    showLoadingOverlay: function () {
      // Remove any existing overlays
      this.removeLoadingOverlay();

      const i18n = this.config.i18n;

      // Create loading overlay HTML
      const loadingHTML =
        '<div class="csmf-loading-overlay">' +
        '<div class="csmf-loading-spinner">' +
        '<div class="csmf-loading-bounce1"></div>' +
        '<div class="csmf-loading-bounce2"></div>' +
        '<div class="csmf-loading-bounce3"></div>' +
        "</div>" +
        '<div class="csmf-loading-text">' +
        (i18n.converting || "Converting...") +
        "</div>" +
        "</div>";

      // Add loading overlay to body
      $("body").append(loadingHTML);
    },

    /**
     * Remove loading overlay
     */
    removeLoadingOverlay: function () {
      $(".csmf-loading-overlay").remove();
    },

    /**
     * Show success popup
     *
     * @param {Object} data Response data
     */
    showSuccessPopup: function (data) {
      // Remove any existing popups
      $(".csmf-popup-overlay").remove();

      const i18n = this.config.i18n;

      // Ensure we have all required data
      const message = data.message || "Template successfully converted to page!";
      const previewUrl = data.preview_url || "#";
      const editUrl = data.edit_url || "#";

      // Create popup HTML
      const popupHTML =
        '<div class="csmf-popup-overlay">' +
        '<div class="csmf-popup">' +
        '<div class="csmf-popup-header">' +
        "<h3>" +
        (i18n.successTitle || "Success!") +
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
        (i18n.preview || "Preview") +
        "</a>" +
        '<a href="' +
        editUrl +
        '" class="button button-primary">' +
        '<span class="dashicons dashicons-edit"></span> ' +
        (i18n.edit || "Edit with Elementor") +
        "</a>" +
        "</div>" +
        "</div>" +
        "</div>";

      // Add popup to body
      $("body").append(popupHTML);

      // Add close handlers
      const popup = $(".csmf-popup-overlay");
      this.setupPopupCloseHandlers(popup);
    },

    /**
     * Show success notice using WordPress notifications
     *
     * @param {Object} data Response data
     */
    showSuccessNotice: function (data) {
      const i18n = this.config.i18n;

      // Create success message
      const $notice = $('<div id="csmf-success-message" class="notice notice-success is-dismissible"><p></p></div>');
      const $content = $notice.find("p");

      // Add message
      $content.text(data.message);

      // Add action buttons
      const $actions = $('<div class="csmf-notice-actions" style="margin-top: 10px;"></div>');

      // Preview button
      const $previewBtn = $(
        '<a href="' +
          data.preview_url +
          '" target="_blank" class="button">' +
          '<span class="dashicons dashicons-visibility" style="margin-top: 3px;"></span> ' +
          (i18n.preview || "Preview") +
          "</a>"
      );
      $actions.append($previewBtn);

      // Edit button
      const $editBtn = $(
        '<a href="' +
          data.edit_url +
          '" class="button button-primary" style="margin-left: 5px;">' +
          '<span class="dashicons dashicons-edit" style="margin-top: 3px;"></span> ' +
          (i18n.edit || "Edit with Elementor") +
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
    },

    /**
     * Format date string
     *
     * @param {string} dateString Date string
     * @return {string} Formatted date
     */
    formatDate: function (dateString) {
      if (!dateString) return "";

      const date = new Date(dateString);
      if (isNaN(date.getTime())) return dateString;

      return date.toLocaleDateString();
    },

    /**
     * Add preview modal functionality
     */
    setupPreviewModal: function () {
      const self = this;

      // Create modal container if it doesn't exist
      if ($("#csmf-preview-modal").length === 0) {
        const $modal = $(
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
        const $card = $(this);
        const $preview = $card.find(".csmf-template-preview");

        // Add preview button if it doesn't exist
        if ($preview.find(".csmf-preview-button").length === 0) {
          const $previewBtn = $(
            '<button class="csmf-preview-button" title="Preview Template">' +
              '<span class="dashicons dashicons-visibility"></span>' +
              "</button>"
          );

          $preview.append($previewBtn);

          // Show preview when clicking on the button
          $previewBtn.on("click", function (e) {
            e.preventDefault();
            e.stopPropagation();

            const templateId = $card.find(".csmf-import-btn").data("id");
            const template = self.state.templates[templateId];

            if (!template) return;

            const $modalBody = $(".csmf-modal-body");
            $modalBody.empty();

            // Add template details to modal
            const $content = $(
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
            const $details = $content.find(".csmf-preview-details");

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
              const importType = $(this).data("type");
              const templateId = $(this).data("id");
              const $originalBtn = $card.find('.csmf-import-btn[data-type="' + importType + '"]');

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
    },

    /**
     * Show conversion success message
     *
     * @param {Object} data Response data from server
     */
    showConversionSuccessMessage: function (data) {
      // Log data for debugging
      console.log("Creating popup with data:", data);

      const i18n = this.config.i18n;

      // Ensure we have all required data or provide defaults
      const message = data.message || "Template successfully converted to page!";
      const previewUrl = data.preview_url || "#";
      const editUrl = data.edit_url || "#";

      // Remove any existing popups first
      $(".csmf-modal-overlay").remove();

      // Create modal HTML
      const modalHTML =
        '<div class="csmf-modal-overlay" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 99999; display: flex; justify-content: center; align-items: center;">' +
        '<div class="csmf-modal" style="background: white; border-radius: 3px; box-shadow: 0 2px 30px rgba(0,0,0,0.3); width: 500px; max-width: 90%; padding: 20px; position: relative; z-index: 100000;">' +
        '<h2 style="margin-top: 0; color: #23282d;">' +
        (i18n.successTitle || "Template Converted Successfully") +
        "</h2>" +
        '<p style="margin-bottom: 1.5em;">' +
        message +
        "</p>" +
        '<div class="csmf-modal-actions" style="margin-top: 20px; text-align: right;">' +
        '<a href="' +
        previewUrl +
        '" target="_blank" class="button" style="margin-left: 5px;">' +
        '<span class="dashicons dashicons-visibility" style="vertical-align: middle; margin-right: 3px;"></span> ' +
        (i18n.preview || "Preview") +
        "</a> " +
        '<a href="' +
        editUrl +
        '" class="button button-primary" style="margin-left: 5px;">' +
        '<span class="dashicons dashicons-edit" style="vertical-align: middle; margin-right: 3px;"></span> ' +
        (i18n.edit || "Edit with Elementor") +
        "</a> " +
        '<button class="button csmf-modal-close" style="margin-left: 5px;">' +
        (i18n.close || "Close") +
        "</button>" +
        "</div>" +
        "</div>" +
        "</div>";

      // Add modal to body with inline styles
      const $modal = $(modalHTML);
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
    },
  };

  // Initialize when document is ready
  $(document).ready(function () {
    TemplateImporter.init();
  });
})(jQuery);
