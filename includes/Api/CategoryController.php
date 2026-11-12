<?php
namespace OwwCommerce\Api;

use OwwCommerce\Core\Container;
use OwwCommerce\Repositories\CategoryRepository;
use OwwCommerce\Models\Category;

/**
 * Controller API untuk OwwCommerce Categories
 */
class CategoryController extends BaseController {
    
    private Container $container;
    protected $namespace = 'owwc/v1';
    protected $rest_base = 'categories';
    
    // Inject repository langsung jika belum diregister di container
    private CategoryRepository $repository;

    public function __construct( Container $container ) {
        $this->container = $container;
        $this->repository = new CategoryRepository();
        
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes() {
        register_rest_route( $this->namespace, '/' . $this->rest_base, [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_items' ],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'create_item' ],
                'permission_callback' => [ $this, 'admin_permission_check' ],
            ]
        ] );

        register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)', [
            [
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'delete_item' ],
                'permission_callback' => [ $this, 'admin_permission_check' ],
            ],
        ] );
    }

    /**
     * Endpoint untuk GET /categories
     */
    public function get_items( $request ) {
        $categories = $this->repository->get_all();
        $response = array_map(function($cat) {
            return (array) $cat;
        }, $categories);
        
        return rest_ensure_response( $response );
    }

    /**
     * Endpoint untuk POST /categories
     */
    public function create_item( $request ) {
        $name = sanitize_text_field( $request->get_param('name') );
        $description = sanitize_textarea_field( $request->get_param('description') );
        $parent_id = absint( $request->get_param('parent_id') ?? 0 );

        if ( empty( $name ) ) {
            return new \WP_Error( 'rest_missing_callback_param', 'Category name is required.', [ 'status' => 400 ] );
        }

        $category = new Category([
            'name'        => $name,
            'description' => $description,
            'parent_id'   => $parent_id,
        ]);

        $id = $this->repository->save( $category );

        if ( ! $id ) {
            return new \WP_Error( 'db_insert_error', 'Could not save category.', [ 'status' => 500 ] );
        }

        return rest_ensure_response([
            'success' => true,
            'category' => (array) $this->repository->get_by_id( $id )
        ]);
    }

    /**
     * Endpoint untuk DELETE /categories/{id}
     */
    public function delete_item( $request ) {
        $id = absint( $request->get_param('id') );

        if ( ! $id ) {
            return new \WP_Error( 'rest_invalid_param', 'Invalid ID.', [ 'status' => 400 ] );
        }

        $deleted = $this->repository->delete( $id );

        if ( ! $deleted ) {
            return new \WP_Error( 'db_delete_error', 'Could not delete category.', [ 'status' => 500 ] );
        }

        return rest_ensure_response([
            'success' => true,
            'message' => 'Category deleted successfully.'
        ]);
    }
}
