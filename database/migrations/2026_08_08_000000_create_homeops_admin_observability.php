<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (!Schema::hasColumn('users', 'is_admin')) {
                    $table->boolean('is_admin')->default(false)->index();
                }
                if (!Schema::hasColumn('users', 'account_status')) {
                    $table->string('account_status', 30)->default('active')->index();
                }
                if (!Schema::hasColumn('users', 'plan_key')) {
                    $table->string('plan_key', 40)->default('core')->index();
                }
                if (!Schema::hasColumn('users', 'last_seen_at')) {
                    $table->timestamp('last_seen_at')->nullable()->index();
                }
                if (!Schema::hasColumn('users', 'suspended_at')) {
                    $table->timestamp('suspended_at')->nullable();
                }
                if (!Schema::hasColumn('users', 'suspension_reason')) {
                    $table->text('suspension_reason')->nullable();
                }
                if (!Schema::hasColumn('users', 'admin_metadata')) {
                    $table->json('admin_metadata')->nullable();
                }
            });
        }

        // Older development databases already have this table. Keeping the creation here makes
        // a fresh install reproducible without depending on a manually-created auth table.
        if (!Schema::hasTable('homeops_api_tokens')) {
            Schema::create('homeops_api_tokens', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->index();
                $table->string('name', 120)->nullable();
                $table->string('token_hash', 64)->unique();
                $table->json('abilities')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->timestamp('last_used_at')->nullable()->index();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamp('revoked_at')->nullable()->index();
                $table->timestamps();
                $table->index(['user_id', 'revoked_at', 'expires_at']);
            });
        }

        if (!Schema::hasTable('homeops_request_logs')) {
            Schema::create('homeops_request_logs', function (Blueprint $table) {
                $table->id();
                $table->uuid('request_id')->unique();
                $table->foreignId('user_id')->nullable()->index();
                $table->foreignId('admin_user_id')->nullable()->index();
                $table->foreignId('home_id')->nullable()->index();
                $table->string('category', 80)->nullable()->index();
                $table->string('action', 160)->nullable()->index();
                $table->string('route', 500)->nullable();
                $table->string('method', 12)->index();
                $table->unsignedSmallInteger('response_status')->index();
                $table->unsignedInteger('duration_ms')->default(0)->index();
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->json('query_data')->nullable();
                $table->json('request_data')->nullable();
                $table->string('error_message', 1000)->nullable();
                $table->timestamp('occurred_at')->index();
                $table->timestamps();
                $table->index(['user_id', 'occurred_at']);
                $table->index(['response_status', 'occurred_at']);
                $table->index(['category', 'occurred_at']);
            });
        }

        if (!Schema::hasTable('homeops_audit_logs')) {
            Schema::create('homeops_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->uuid('request_id')->nullable()->index();
                $table->string('actor_type', 30)->default('user')->index();
                $table->foreignId('actor_user_id')->nullable()->index();
                $table->foreignId('target_user_id')->nullable()->index();
                $table->foreignId('home_id')->nullable()->index();
                $table->string('event_type', 100)->index();
                $table->string('entity_type', 100)->nullable()->index();
                $table->string('entity_id', 100)->nullable()->index();
                $table->string('action', 100)->index();
                $table->string('summary', 500);
                $table->json('before_data')->nullable();
                $table->json('after_data')->nullable();
                $table->json('metadata')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->timestamp('occurred_at')->index();
                $table->timestamps();
                $table->index(['target_user_id', 'occurred_at']);
                $table->index(['entity_type', 'entity_id', 'occurred_at'], 'homeops_audit_entity_history_idx');
            });
        }

        if (!Schema::hasTable('homeops_support_cases')) {
            Schema::create('homeops_support_cases', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->index();
                $table->foreignId('home_id')->nullable()->index();
                $table->foreignId('assigned_admin_user_id')->nullable()->index();
                $table->string('status', 30)->default('open')->index();
                $table->string('priority', 20)->default('normal')->index();
                $table->string('channel', 30)->default('internal')->index();
                $table->string('subject', 220);
                $table->text('summary')->nullable();
                $table->string('external_reference', 160)->nullable()->index();
                $table->timestamp('opened_at')->index();
                $table->timestamp('last_customer_contact_at')->nullable()->index();
                $table->timestamp('last_admin_contact_at')->nullable()->index();
                $table->timestamp('resolved_at')->nullable()->index();
                $table->timestamps();
                $table->index(['status', 'priority', 'opened_at']);
            });
        }

        if (!Schema::hasTable('homeops_support_messages')) {
            Schema::create('homeops_support_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('support_case_id')->index();
                $table->string('author_type', 30)->default('admin')->index();
                $table->foreignId('author_user_id')->nullable()->index();
                $table->string('direction', 20)->default('internal')->index();
                $table->string('channel', 30)->default('internal')->index();
                $table->longText('body');
                $table->string('external_message_id', 180)->nullable()->index();
                $table->json('metadata')->nullable();
                $table->timestamp('happened_at')->index();
                $table->timestamps();
                $table->index(['support_case_id', 'happened_at']);
            });
        }

        if (!Schema::hasTable('homeops_customer_notes')) {
            Schema::create('homeops_customer_notes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->index();
                $table->foreignId('admin_user_id')->index();
                $table->string('note_type', 30)->default('general')->index();
                $table->text('body');
                $table->boolean('pinned')->default(false)->index();
                $table->timestamps();
                $table->index(['user_id', 'pinned', 'created_at']);
            });
        }

        if (!Schema::hasTable('homeops_feature_flags')) {
            Schema::create('homeops_feature_flags', function (Blueprint $table) {
                $table->id();
                $table->string('key', 100)->unique();
                $table->string('name', 160);
                $table->text('description')->nullable();
                $table->boolean('enabled')->default(false)->index();
                $table->unsignedTinyInteger('rollout_percentage')->default(100);
                $table->json('config')->nullable();
                $table->foreignId('updated_by')->nullable()->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('homeops_feature_flag_overrides')) {
            Schema::create('homeops_feature_flag_overrides', function (Blueprint $table) {
                $table->id();
                $table->foreignId('feature_flag_id')->index();
                $table->foreignId('user_id')->index();
                $table->boolean('enabled');
                $table->string('reason', 500)->nullable();
                $table->foreignId('admin_user_id')->nullable()->index();
                $table->timestamps();
                $table->unique(['feature_flag_id', 'user_id'], 'homeops_flag_user_unique');
            });
        }

        if (!Schema::hasTable('homeops_cms_entries')) {
            Schema::create('homeops_cms_entries', function (Blueprint $table) {
                $table->id();
                $table->string('key', 140)->unique();
                $table->string('area', 60)->default('marketing')->index();
                $table->string('label', 180);
                $table->json('value_json');
                $table->string('status', 20)->default('draft')->index();
                $table->timestamp('published_at')->nullable()->index();
                $table->foreignId('updated_by')->nullable()->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('homeops_system_events')) {
            Schema::create('homeops_system_events', function (Blueprint $table) {
                $table->id();
                $table->uuid('request_id')->nullable()->index();
                $table->foreignId('user_id')->nullable()->index();
                $table->string('severity', 20)->default('info')->index();
                $table->string('category', 80)->default('application')->index();
                $table->string('source', 160)->nullable()->index();
                $table->string('message', 1000);
                $table->json('context')->nullable();
                $table->timestamp('occurred_at')->index();
                $table->timestamps();
                $table->index(['severity', 'occurred_at']);
            });
        }

        if (!Schema::hasTable('homeops_data_requests')) {
            Schema::create('homeops_data_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->index();
                $table->string('request_type', 30)->index();
                $table->string('status', 30)->default('open')->index();
                $table->text('reason')->nullable();
                $table->foreignId('opened_by_admin_user_id')->nullable()->index();
                $table->foreignId('completed_by_admin_user_id')->nullable()->index();
                $table->timestamp('requested_at')->index();
                $table->timestamp('completed_at')->nullable()->index();
                $table->timestamps();
                $table->index(['status', 'requested_at']);
            });
        }

        $this->seedFeatureFlags();
        $this->seedCmsEntries();
    }

    public function down(): void
    {
        // Forward-only by design. These tables are the evidentiary trail for support, security,
        // and customer history; a rollback should never silently erase them.
    }

    private function seedFeatureFlags(): void
    {
        if (!Schema::hasTable('homeops_feature_flags')) {
            return;
        }

        $flags = [
            ['receipt_scanner', 'Receipt scanner', 'AI-assisted receipt capture and verification.', true],
            ['month_close', 'Month close', 'Monthly reconciliation and closeout workflow.', true],
            ['documents', 'Document vault', 'Property document storage and retrieval.', true],
            ['financing', 'Financing workspace', 'Financing and account tracking workspace.', true],
            ['advanced_reports', 'Advanced reports', 'Expanded reporting and export surfaces.', false],
            ['household_members', 'Household members', 'Shared household access and roles.', false],
        ];

        foreach ($flags as [$key, $name, $description, $enabled]) {
            DB::table('homeops_feature_flags')->updateOrInsert(
                ['key' => $key],
                [
                    'name' => $name,
                    'description' => $description,
                    'enabled' => $enabled ? 1 : 0,
                    'rollout_percentage' => 100,
                    'config' => json_encode(new stdClass()),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }

    private function seedCmsEntries(): void
    {
        if (!Schema::hasTable('homeops_cms_entries')) {
            return;
        }

        $entries = [
            ['marketing.hero', 'marketing', 'Marketing hero', [
                'eyebrow' => 'THE OPERATING SYSTEM FOR HOMEOWNERSHIP',
                'headline' => 'Run your home',
                'emphasis' => 'like it matters.',
                'body' => 'HomeOps turns bills, receipts, maintenance, documents, financing and everyday spending into one calm, beautifully organized command center.',
                'primary_cta' => 'Explore plans',
            ]],
            ['marketing.security', 'marketing', 'Security section', [
                'headline' => 'Built with security in the architecture.',
                'body' => 'HomeOps is being built around scoped property data, explicit access, reviewable actions and a clear ownership model—because the operational record of your home deserves serious treatment.',
            ]],
            ['support.status_banner', 'support', 'Support status banner', ['enabled' => false, 'tone' => 'info', 'message' => 'All systems operational.']],
        ];

        foreach ($entries as [$key, $area, $label, $value]) {
            DB::table('homeops_cms_entries')->updateOrInsert(
                ['key' => $key],
                [
                    'area' => $area,
                    'label' => $label,
                    'value_json' => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'status' => 'draft',
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }
};
