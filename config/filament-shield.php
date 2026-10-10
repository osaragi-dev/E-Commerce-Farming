<?php

declare(strict_types=1);
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\GenerateInvoice;
use App\Filament\Pages\Pos;
use App\Filament\Pages\PrinterSettings;
use App\Filament\Resources\CompanyProfile\CompanyProfileResource;
use App\Filament\Resources\ProductVariants\ProductVariantResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Stocks\StockResource;
use App\Filament\Resources\Users\UserResource;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;

return [

    /*
    |--------------------------------------------------------------------------
    | Shield Resource
    |--------------------------------------------------------------------------
    |
    | Here you may configure the built-in role management resource. You can
    | customize the URL, choose whether to show model paths, group it under
    | a cluster, and decide which permission tabs to display.
    |
    */

    'shield_resource' => [
        'slug' => 'shield/roles',
        'show_model_path' => true,
        'cluster' => null,
        'tabs' => [
            'pages' => true,
            'widgets' => true,
            'resources' => true,
            'custom_permissions' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-Tenancy
    |--------------------------------------------------------------------------
    |
    | When your application supports teams, Shield will automatically detect
    | and configure the tenant model during setup. This enables tenant-scoped
    | roles and permissions throughout your application.
    |
    */

    'tenant_model' => null,

    /*
    |--------------------------------------------------------------------------
    | User Model
    |--------------------------------------------------------------------------
    |
    | This value contains the class name of your user model. This model will
    | be used for role assignments and must implement the HasRoles trait
    | provided by the Spatie\Permission package.
    |
    */

    'auth_provider_model' => 'App\\Models\\User',

    /*
    |--------------------------------------------------------------------------
    | Super Admin
    |--------------------------------------------------------------------------
    |
    | Here you may define a super admin that has unrestricted access to your
    | application. You can choose to implement this via Laravel's gate system
    | or as a traditional role with all permissions explicitly assigned.
    |
    */

    'super_admin' => [
        'enabled' => true,
        'name' => 'super_admin',
        'define_via_gate' => true,
        'intercept_gate' => 'before',
    ],

    /*
    |--------------------------------------------------------------------------
    | Panel User
    |--------------------------------------------------------------------------
    |
    | When enabled, Shield will create a basic panel user role that can be
    | assigned to users who should have access to your Filament panels but
    | don't need any specific permissions beyond basic authentication.
    |
    */

    'panel_user' => [
        'enabled' => false,
        'name' => 'panel_user',
    ],

    /*
    |--------------------------------------------------------------------------
    | Permission Builder
    |--------------------------------------------------------------------------
    |
    | You can customize how permission keys are generated to match your
    | preferred naming convention and organizational standards. Shield uses
    | these settings when creating permission names from your resources.
    |
    | Supported formats: snake, kebab, pascal, camel, upper_snake, lower_snake
    |
    | Note: The separator must not conflict with the case format's own
    | delimiter. For example, `_` cannot be used with snake/lower_snake/
    | upper_snake, and `-` cannot be used with kebab.
    |
    | When `format_custom_permission_keys` is true (default), custom
    | permissions defined below will have their keys formatted according to
    | the case setting. If your custom permissions come from external sources
    | (e.g. Terraform, Keycloak) and must remain unchanged, set this to false.
    | When using the separator in custom permission definitions, each segment
    | will be formatted independently (e.g. 'view:system_log' with pascal
    | case becomes 'View:SystemLog').
    |
    */

    'permissions' => [
        'separator' => ':',
        'case' => 'pascal',
        'generate' => true,
        'format_custom_permission_keys' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Policies
    |--------------------------------------------------------------------------
    |
    | Shield can automatically generate Laravel policies for your resources.
    | Generated policies mirror each model's location: models under
    | app/Models map into the path below (keeping their nesting), models in
    | any other "Models" directory get a sibling "Policies" directory, and
    | vendor models fall back to the path below. When merge is enabled, the
    | methods below will be combined with any resource-specific methods you
    | define in the resources section.
    |
    */

    'policies' => [
        'path' => app_path('Policies'),
        // false: a resource listed in `resources.manage` below uses ONLY
        // that method list. true: its list gets merged/unioned with the
        // full default `methods` list, which defeats the purpose of
        // restricting anything (e.g. CompanyProfileResource's ['viewAny',
        // 'update'] override would still end up with all 11 methods).
        'merge' => false,
        'generate' => true,
        'methods' => [
            'viewAny', 'view', 'create', 'update', 'delete', 'deleteAny', 'restore',
            'restoreAny', 'replicate', 'reorder', 'export',
        ],
        'single_parameter_methods' => [
            'viewAny',
            'create',
            'deleteAny',
            'forceDeleteAny',
            'restoreAny',
            'reorder',
            'export',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Localization
    |--------------------------------------------------------------------------
    |
    | Shield supports multiple languages out of the box. When enabled, you
    | can provide translated labels for permissions to create a more
    | localized experience for your international users.
    |
    */

    'localization' => [
        'enabled' => false,
        'key' => 'filament-shield::filament-shield.resource_permission_prefixes_labels',
    ],

    /*
    |--------------------------------------------------------------------------
    | Resources
    |--------------------------------------------------------------------------
    |
    | Here you can fine-tune permissions for specific Filament resources.
    | Use the 'manage' array to override the default policy methods for
    | individual resources, giving you granular control over permissions.
    |
    */

    'resources' => [
        'subject' => 'model',
        'manage' => [
            UserResource::class => ['viewAny', 'view', 'update', 'export', 'impersonate'],
            RoleResource::class => [
                'viewAny',
                'view',
                'create',
                'update',
                'delete',
                'deleteAny',
                'restore',
                'restoreAny',
                'replicate',
            ],
            // CompanyProfile is a singleton settings page: there is no
            // ViewRecord page (only an Edit page), and canCreate()/
            // canDelete() are hardcoded to false. "View" alone has no page
            // to apply to — opening the resource always goes to Edit and
            // requires Update, so exposing View/Create/Delete/etc as
            // checkboxes was misleading and caused a 403 when a role had
            // ViewAny+View but not Update.
            CompanyProfileResource::class => [
                'viewAny',
                'update',
            ],
        ],
        'exclude' => [
            // StockResource uses WarehouseStock, but its access and exports
            // use dedicated Stock permissions registered below.
            StockResource::class,

            // ProductVariantResource has no dedicated menu — variants are
            // managed from a Repeater inside the Product form (TCS
            // revision, see ProductVariantResource's class docblock), and
            // that Repeater is gated only by the Product permission, never
            // by ProductVariantPolicy. Leaving this resource included made
            // Shield render a separate "Product Variant" permission
            // section that duplicated/overlapped "Product" on the Roles
            // matrix without controlling anything a user can reach through
            // the UI. Excluded here so only "Product" represents this
            // function on the matrix. ProductVariantPolicy and any
            // permission rows already in the database are left untouched;
            // the hidden resource route still exists and is still
            // policy-protected if visited directly.
            ProductVariantResource::class,

        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    |
    | Most Filament pages only require view permissions. Pages listed in the
    | exclude array will be skipped during permission generation and won't
    | appear in your role management interface.
    |
    */

    'pages' => [
        'subject' => 'class',
        'prefix' => 'view',
        'exclude' => [
            Pos::class,
            GenerateInvoice::class,
            PrinterSettings::class,
            Dashboard::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Widgets
    |--------------------------------------------------------------------------
    |
    | Like pages, widgets typically only need view permissions. Add widgets
    | to the exclude array if you don't want them to appear in your role
    | management interface.
    |
    */

    'widgets' => [
        'subject' => 'class',
        'prefix' => 'view',
        'exclude' => [
            AccountWidget::class,
            FilamentInfoWidget::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Permissions
    |--------------------------------------------------------------------------
    |
    | Sometimes you need permissions that don't map to resources, pages, or
    | widgets. Define any custom permissions here and they'll be available
    | when editing roles in your application.
    |
    | Keys are formatted per the Permission Builder settings above; set
    | permissions.format_custom_permission_keys to false to use them as-is.
    |
    | NOTE: Two groups of permissions are registered here because Shield's
    | standard generator can't produce them on its own:
    |
    | 1. StockResource uses WarehouseStock, but exposes dedicated Stock
    |    permissions instead of model-based WarehouseStock permissions.
    |    See StockResource::canViewAny()/canEdit() and its export actions.
    |
    | 2. AdvanceStatus:Order and Verify:Payment/Reject:Payment are
    |    fine-grained action permissions kept separate from the generic
    |    Update:Order / Update:Payment on purpose (warehouse fulfillment
    |    and payment confirmation are distinct responsibilities from
    |    general record editing). See OrderPolicy::advanceStatus() and
    |    PaymentPolicy::verify()/reject().
    |
    | Both groups are seeded manually in RoleSeeder.
    |
    */

    'custom_permissions' => [
        'Cancel:Repack' => 'Cancel Convert',
        'ViewAny:Invoice' => 'View Invoice List',
        'View:Invoice' => 'View Invoice',
        'Create:Invoice' => 'Generate Invoice',
        'Export:Invoice' => 'Download Invoice',
        'Delete:Invoice' => 'Delete Unpaid Invoice',
        'ViewAny:Stock' => 'View Stock List',
        'Update:Stock' => 'Update Stock',
        'Export:Stock' => 'Export Stock',
        'AdvanceStatus:Order' => 'Advance Order Status',
        'Verify:Payment' => 'Verify Payment',
        'Reject:Payment' => 'Reject Payment',
        'Void:Payment' => 'Void Payment',
        'Ship:StockMovement' => 'Ship Stock Movement',
        'Receive:StockMovement' => 'Receive Stock Movement',
        'RejectDispatch:StockMovement' => 'Reject Stock Movement Dispatch',
        'Cancel:StockMovement' => 'Cancel Stock Movement',
    ],

    /*
    |--------------------------------------------------------------------------
    | Entity Discovery
    |--------------------------------------------------------------------------
    |
    | By default, Shield only looks for entities in your default Filament
    | panel. Enable these options if you're using multiple panels and want
    | Shield to discover entities across all of them.
    |
    */

    'discovery' => [
        'discover_all_resources' => false,
        'discover_all_widgets' => false,
        'discover_all_pages' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Role Policy
    |--------------------------------------------------------------------------
    |
    | Shield can automatically register a policy for role management itself.
    | This lets you control who can manage roles using Laravel's built-in
    | authorization system. Requires a RolePolicy class in your app.
    |
    */

    'register_role_policy' => true,

];
