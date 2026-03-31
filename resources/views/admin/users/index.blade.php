@extends('admin.layout')

@section('title', 'Users')

@section('content')
<div class="page-header">
    <h1>Users</h1>
</div>

<div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start">

    <div class="card" style="flex:0 0 300px">
        <div style="font-weight:600;color:#f1f5f9;margin-bottom:20px">New user</div>
        <form method="POST" action="{{ route('admin.users.store') }}">
            @csrf
            <div style="margin-bottom:14px">
                <label style="display:block;font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#475569;margin-bottom:6px">Name</label>
                <input type="text" name="name" value="{{ old('name') }}" style="width:100%" required>
                @error('name')<div style="color:#fca5a5;font-size:0.8rem;margin-top:5px">{{ $message }}</div>@enderror
            </div>
            <div style="margin-bottom:14px">
                <label style="display:block;font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#475569;margin-bottom:6px">Phone / Login</label>
                <input type="text" name="email" value="{{ old('email') }}" inputmode="numeric" style="width:100%" required>
                @error('email')<div style="color:#fca5a5;font-size:0.8rem;margin-top:5px">{{ $message }}</div>@enderror
            </div>
            <div style="margin-bottom:20px">
                <label style="display:block;font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#475569;margin-bottom:6px">Password</label>
                <input type="password" name="password" style="width:100%" required>
                @error('password')<div style="color:#fca5a5;font-size:0.8rem;margin-top:5px">{{ $message }}</div>@enderror
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">Create user</button>
        </form>
    </div>

    <div style="flex:1;min-width:280px">
        <div class="card" style="padding:0;overflow:hidden">
            <table>
                <thead>
                    <tr>
                        <th style="width:40px">#</th>
                        <th>Name</th>
                        <th>Login</th>
                        <th>Created</th>
                        <th style="width:60px"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($users as $user)
                    <tr>
                        <td style="color:#334155;font-size:0.8rem">{{ $user->id }}</td>
                        <td style="font-weight:500;color:#c7d2fe">{{ $user->name }}</td>
                        <td style="color:#64748b;font-size:0.875rem">{{ $user->email }}</td>
                        <td style="color:#334155;font-size:0.8rem">{{ $user->created_at->diffForHumans() }}</td>
                        <td>
                            @if($user->id !== auth()->id())
                            <form method="POST" action="{{ route('admin.users.destroy', $user) }}" onsubmit="return confirm('Delete this user?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-sm">Del</button>
                            </form>
                            @else
                            <span style="color:#334155;font-size:0.75rem">you</span>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" style="text-align:center;padding:40px;color:#334155">No users yet.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection
